<?php
namespace Mapping;

use Doctrine\ORM\Events;
use Mapping\Db\Event\Listener\DetachOrphanMappings;
use Omeka\Module\AbstractModule;
use Zend\EventManager\Event;
use Zend\EventManager\SharedEventManagerInterface;
use Zend\Mvc\MvcEvent;
use Zend\ServiceManager\ServiceLocatorInterface;

class Module extends AbstractModule
{
    public function getConfig()
    {
        return include __DIR__ . '/config/module.config.php';
    }

    public function onBootstrap(MvcEvent $event)
    {
        parent::onBootstrap($event);

        $acl = $this->getServiceLocator()->get('Omeka\Acl');
        $acl->allow(
            null,
            ['Mapping\Api\Adapter\MappingMarkerAdapter','Mapping\Api\Adapter\MappingAdapter'],
            ['search', 'read']
        );

        $em = $this->getServiceLocator()->get('Omeka\EntityManager');
        $em->getEventManager()->addEventListener(
            Events::preFlush,
            new DetachOrphanMappings
        );
    }

    public function install(ServiceLocatorInterface $serviceLocator)
    {
        $conn = $serviceLocator->get('Omeka\Connection');
        $conn->exec('
CREATE TABLE mapping_marker (id INT AUTO_INCREMENT NOT NULL, item_id INT NOT NULL, media_id INT DEFAULT NULL, lat DOUBLE PRECISION NOT NULL, lng DOUBLE PRECISION NOT NULL, `label` VARCHAR(255) DEFAULT NULL, INDEX IDX_667C9244126F525E (item_id), INDEX IDX_667C9244EA9FDD75 (media_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
CREATE TABLE mapping (id INT AUTO_INCREMENT NOT NULL, item_id INT NOT NULL, default_zoom SMALLINT DEFAULT NULL, default_lat DOUBLE PRECISION DEFAULT NULL, default_lng DOUBLE PRECISION DEFAULT NULL, UNIQUE INDEX UNIQ_49E62C8A126F525E (item_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
ALTER TABLE mapping_marker ADD CONSTRAINT FK_667C9244126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE;
ALTER TABLE mapping_marker ADD CONSTRAINT FK_667C9244EA9FDD75 FOREIGN KEY (media_id) REFERENCES media (id) ON DELETE SET NULL;
ALTER TABLE mapping ADD CONSTRAINT FK_49E62C8A126F525E FOREIGN KEY (item_id) REFERENCES item (id) ON DELETE CASCADE;');
    }

    public function uninstall(ServiceLocatorInterface $serviceLocator)
    {
        $conn = $serviceLocator->get('Omeka\Connection');
        $conn->exec('
DROP TABLE IF EXISTS mapping;
DROP TABLE IF EXISTS mapping_marker');
    }

    public function attachListeners(SharedEventManagerInterface $sharedEventManager)
    {
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            ['view.add.form.after', 'view.edit.form.after'],
            function (Event $event) {
                echo $event->getTarget()->partial('mapping/index/form.phtml');
            }
        );
        $sharedEventManager->attach(
            ['Omeka\Controller\Admin\Item', 'Omeka\Controller\Site\Item'],
            'view.show.after',
            function (Event $event) {
                echo $event->getTarget()->partial('mapping/index/show.phtml');
            }
        );
        $sharedEventManager->attach(
            'Omeka\Controller\Admin\Item',
            ['view.add.section_nav', 'view.edit.section_nav', 'view.show.section_nav'],
            function (Event $event) {
                if ('view.show.section_nav' === $event->getName()) {
                    // Don't render the mapping tab if there is no mapping data.
                    $itemJson = $event->getParam('resource')->jsonSerialize();
                    if (!isset($itemJson['o-module-mapping:marker'])
                        && !isset($itemJson['o-module-mapping:mapping'])
                    ) {
                        return;
                    }
                }
                $sectionNav = $event->getParam('section_nav');
                $sectionNav['mapping-section'] = 'Mapping';
                $event->setParam('section_nav', $sectionNav);
            }
        );
        $sharedEventManager->attach(
            'Omeka\Api\Representation\ItemRepresentation',
            'rep.resource.json',
            [$this, 'filterItemJsonLd']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.hydrate.post',
            [$this, 'handleMapping']
        );
        $sharedEventManager->attach(
            'Omeka\Api\Adapter\ItemAdapter',
            'api.hydrate.post',
            [$this, 'handleMarkers']
        );
    }

    public function filterItemJsonLd(Event $event)
    {
        $item = $event->getTarget();
        $jsonLd = $event->getParam('jsonLd');
        $api = $this->getServiceLocator()->get('Omeka\ApiManager');

        $response = $api->search('mapping_markers', ['item_id' => $item->id()]);
        if ($response->getTotalResults()) {
            $jsonLd['o-module-mapping:marker'] = $response->getContent();
        }

        $response = $api->search('mappings', ['item_id' => $item->id()]);
        if ($response->getTotalResults()) {
            $mapping = $response->getContent();
            $jsonLd['o-module-mapping:mapping'] = $mapping[0];
        }

        $event->setParam('jsonLd', $jsonLd);
    }

    public function handleMapping(Event $event)
    {
        $itemAdapter = $event->getTarget();
        $request = $event->getParam('request');

        if (!$itemAdapter->shouldHydrate($request, 'o-module-mapping:mapping')) {
            return;
        }

        $mappingsAdapter = $itemAdapter->getAdapter('mappings');
        $mappingData = $request->getValue('o-module-mapping:mapping', []);

        $mappingId = null;
        $defaultZoom = null;
        $defaultLat = null;
        $defaultLng = null;

        if (isset($mappingData['o:id'])
            && is_numeric($mappingData['o:id'])
        ) {
            $mappingId = $mappingData['o:id'];
        }
        if (isset($mappingData['o-module-mapping:default_zoom'])
            && is_numeric($mappingData['o-module-mapping:default_zoom'])
        ) {
            $defaultZoom = $mappingData['o-module-mapping:default_zoom'];
        }
        if (isset($mappingData['o-module-mapping:default_lat'])
            && is_numeric($mappingData['o-module-mapping:default_lat'])
        ) {
            $defaultLat = $mappingData['o-module-mapping:default_lat'];
        }
        if (isset($mappingData['o-module-mapping:default_lng'])
            && is_numeric($mappingData['o-module-mapping:default_lng'])
        ) {
            $defaultLng = $mappingData['o-module-mapping:default_lng'];
        }

        if (null === $defaultZoom || null === $defaultLat || null === $defaultLng) {
            // This request has no mapping data. If a mapping for this item
            // exists, delete it. If no mapping for this item exists, do nothing.
            if (null !== $mappingId) {
                // Delete mapping
                $subRequest = new \Omeka\Api\Request('delete', 'mappings');
                $subRequest->setId($mappingId);
                $mappingsAdapter->deleteEntity($subRequest);
            }
        } else {
            // This request has mapping data. If a mapping for this item exists,
            // update it. If no mapping for this item exists, create it.
            if ($mappingId) {
                // Update mapping
                $subRequest = new \Omeka\Api\Request('update', 'mappings');
                $subRequest->setId($mappingData['o:id']);
                $subRequest->setContent($mappingData);
                $mapping = $mappingsAdapter->findEntity($mappingData['o:id'], $subRequest);
                $mappingsAdapter->hydrateEntity($subRequest, $mapping, new \Omeka\Stdlib\ErrorStore);
            } else {
                // Create mapping
                $subRequest = new \Omeka\Api\Request('create', 'mappings');
                $subRequest->setContent($mappingData);
                $mapping = new \Mapping\Entity\Mapping;
                $mapping->setItem($event->getParam('entity'));
                $mappingsAdapter->hydrateEntity($subRequest, $mapping, new \Omeka\Stdlib\ErrorStore);
                $mappingsAdapter->getEntityManager()->persist($mapping);
            }
        }
    }

    public function handleMarkers(Event $event)
    {
        $itemAdapter = $event->getTarget();
        $request = $event->getParam('request');

        if (!$itemAdapter->shouldHydrate($request, 'o-module-mapping:marker')) {
            return;
        }

        $item = $event->getParam('entity');
        $entityManager = $itemAdapter->getEntityManager();
        $markersAdapter = $itemAdapter->getAdapter('mapping_markers');
        $retainMarkerIds = [];

        // Create/update markers passed in the request.
        foreach ($request->getValue('o-module-mapping:marker', []) as $markerData) {
            if (isset($markerData['o:id'])) {
                $subRequest = new \Omeka\Api\Request('update', 'mapping_markers');
                $subRequest->setId($markerData['o:id']);
                $subRequest->setContent($markerData);
                $marker = $markersAdapter->findEntity($markerData['o:id'], $subRequest);
                $markersAdapter->hydrateEntity($subRequest, $marker, new \Omeka\Stdlib\ErrorStore);
                $retainMarkerIds[] = $marker->getId();
            } else {
                $subRequest = new \Omeka\Api\Request('create', 'mapping_markers');
                $subRequest->setContent($markerData);
                $marker = new \Mapping\Entity\MappingMarker;
                $marker->setItem($item);
                $markersAdapter->hydrateEntity($subRequest, $marker, new \Omeka\Stdlib\ErrorStore);
                $entityManager->persist($marker);
            }
        }

        // Delete existing markers not passed in the request.
        $existingMarkers = [];
        if ($item->getId()) {
            $dql = 'SELECT mm FROM Mapping\Entity\MappingMarker mm INDEX BY mm.id WHERE mm.item = ?1';
            $query = $entityManager->createQuery($dql)->setParameter(1, $item->getId());
            $existingMarkers = $query->getResult();
        }
        foreach ($existingMarkers as $existingMarkerId => $existingMarker) {
            if (!in_array($existingMarkerId, $retainMarkerIds)) {
                $entityManager->remove($existingMarker);
            }
        }
    }
}

