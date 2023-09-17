<?php

/**
 * @file plugins/generic/datacite/classes/PubObjectCache.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PubObjectCache
 *
 * @ingroup plugins
 *
 * @brief A cache for publication objects required during export.
 */

namespace APP\plugins\generic\datacite\classes;

use APP\monograph\Chapter;
use APP\publication\Publication;
use APP\publicationFormat\PublicationFormat;
use DataObject;

class PubObjectCache
{
    public array $_objectCache = [];


    //
    // Public API
    //
    /**
     * Add a publishing object to the cache.
     *
     * @param DataObject $object
     * @param Publication|null $parent Only required when adding a publication format or chapter.
     */
    public function add(DataObject $object, ?Publication $parent = null): void
    {
        if ($object instanceof Publication) {
            $this->_insertInternally($object, 'publication', $object->getId());
        }
        if ($object instanceof Chapter) {
            assert($parent instanceof Publication);
            $this->_insertInternally($object, 'chapter', $object->getSourceChapterId());
            if ($parent) {
                $this->_insertInternally($object, 'chaptersByPublication', $parent->getId(), $object->getSourceChapterId());
            }

        }
        if ($object instanceof PublicationFormat) {
            assert($parent instanceof Publication);
            $this->_insertInternally($object, 'publicationFormats', $object->getId());
            if ($parent) {
                $this->_insertInternally($object, 'publicationFormatsByPublication', $parent->getId(), $object->getId());
            }
        }
    }

    /**
     * Marks the given cache id "complete", i.e. it
     * contains all child objects for the given object
     * id.
     *
     * @param string $cacheId
     * @param string $objectId
     */
    public function markComplete(string $cacheId, string $objectId): void
    {
        assert(is_array($this->_objectCache[$cacheId][$objectId]));
        $this->_objectCache[$cacheId][$objectId]['complete'] = true;

        // Order objects in the completed cache by ID.
        ksort($this->_objectCache[$cacheId][$objectId]);
    }

    /**
     * Retrieve (an) object(s) from the cache.
     *
     * NB: You must check whether an object is in the cache
     * before you try to retrieve it with this method.
     *
     * @param string $cacheId
     * @param int    $id1
     * @param ?int   $id2
     *
     * @return mixed|void
     */
    public function get(string $cacheId, int $id1, ?int $id2 = null)
    {
        assert($this->isCached($cacheId, $id1, $id2));
        if (is_null($id2)) {
            $returner = $this->_objectCache[$cacheId][$id1];
            if (is_array($returner)) {
                unset($returner['complete']);
            }
            return $returner;
        } else {
            return $this->_objectCache[$cacheId][$id1][$id2];
        }
    }

    /**
     * Check whether a given object is in the cache.
     *
     * @param string $cacheId
     * @param int $id1
     * @param ?int $id2
     *
     * @return bool
     */
    public function isCached(string $cacheId, int $id1, ?int $id2 = null): bool
    {
        if (!isset($this->_objectCache[$cacheId])) {
            return false;
        }

        if (is_null($id2)) {
            if (!isset($this->_objectCache[$cacheId][$id1])) {
                return false;
            }
            if (is_array($this->_objectCache[$cacheId][$id1])) {
                return isset($this->_objectCache[$cacheId][$id1]['complete']);
            } else {
                return true;
            }
        } else {
            return isset($this->_objectCache[$cacheId][$id1][$id2]);
        }
    }


    //
    // Private helper methods
    //
    /**
     * Insert an object into the cache.
     *
     * @param object $object
     * @param string $cacheId
     * @param int $id1
     * @param ?int $id2
     */
    public function _insertInternally(object $object, string $cacheId, int $id1, ?int $id2 = null): void
    {
        if ($this->isCached($cacheId, $id1, $id2)) {
            return;
        }

        if (!isset($this->_objectCache[$cacheId])) {
            $this->_objectCache[$cacheId] = [];
        }

        if (is_null($id2)) {
            $this->_objectCache[$cacheId][$id1] = $object;
        } else {
            if (!isset($this->_objectCache[$cacheId][$id1])) {
                $this->_objectCache[$cacheId][$id1] = [];
            }
            $this->_objectCache[$cacheId][$id1][$id2] = $object;
        }
    }
}
