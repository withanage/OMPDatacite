<?php

/**
 * @file plugins/generic/datacite/classes/DOIPubIdExportPlugin.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DOIPubIdExportPlugin
 *
 * @ingroup plugins
 *
 * @brief Basis class for DOI XML metadata export plugins
 */

namespace APP\plugins\generic\datacite\classes;

use APP\facades\Repo;
use APP\monograph\Chapter;
use APP\publication\Publication;
use APP\publicationFormat\PublicationFormat;
use APP\template\TemplateManager;
use PKP\context\Context;
use PKP\core\PKPString;


abstract class DOIPubIdExportPlugin extends PubObjectsExportPlugin
{
    // Configuration errors.
    public const DOI_EXPORT_CONFIG_ERROR_DOIPREFIX = 0x01;

    // The name of the setting used to save the registered DOI.
    public const DOI_EXPORT_REGISTERED_DOI = 'registeredDoi';

    /**
     * @copydoc ImportExportPlugin::display()
     */
    public function display($args, $request): void
    {
        switch (array_shift($args)) {
            case 'index':
            case '':
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->display($this->getTemplateResource('index.tpl'));
                break;
            default:
                parent::display($args, $request);
        }
    }

    /**
     * Get pub ID type
     *
     * @return string
     */
    public function getPubIdType(): string
    {
        return 'doi';
    }

    /**
     * Get pub ID display type
     *
     * @return string
     */
    public function getPubIdDisplayType(): string
    {
        return 'DOI';
    }

    /**
     * Mark selected publication as registered.
     *
     * @param Context $context
     * @param array $objects Array of published publications, chapters or publication formats
     */
    public function markRegistered(Context $context, array $objects): void
    {
        foreach ($objects as $object) {
            $doiId = $object->getData('doiId');

            if ($doiId != null) {
                Repo::doi()->markRegistered($doiId);
            }
        }
    }

    /**
     * Saving object's DOI to the object's
     * "registeredDoi" setting.
     * We prefix the setting with the plugin's
     * id so that we do not get name clashes
     * when several DOI registration plug-ins
     * are active at the same time.
     *
     * @param Context $context
     * @param Publication|Chapter|PublicationFormat $object
     * @param string $testPrefix
     */
    public function saveRegisteredDoi(Context $context, Publication|Chapter|PublicationFormat $object, string $testPrefix = '10.1234'): void
    {
        $registeredDoi = $object->getStoredPubId('doi');
        assert(!empty($registeredDoi));
        if ($this->isTestMode($context)) {
            $registeredDoi = $this->createTestDOI($registeredDoi, $testPrefix);
        }
        $object->setData($this->getPluginSettingsPrefix() . '::' . self::DOI_EXPORT_REGISTERED_DOI, $registeredDoi);
        $this->updateObject($object);
    }

    /**
     * Get a list of additional setting names that should be stored with the objects.
     *
     * @return array
     */
    protected function _getObjectAdditionalSettings(): array
    {
        return array_merge(parent::_getObjectAdditionalSettings(), [
            $this->getPluginSettingsPrefix() . '::' . self::DOI_EXPORT_REGISTERED_DOI,
        ]);
    }

    /**
     * Get published publications with a DOI assigned from publication IDs.
     *
     * @param array $publicationIds
     * @param Context $context
     *
     * @return array
     */
    public function getPublishedPublications(array $publicationIds, Context $context): array
    {
        $validPublishedPublications = parent::getPublishedPublications($publicationIds, $context);
        $validPublishedPublicationsWithDoi = [];
        /** @var Publication $publication */
        foreach ($validPublishedPublications as $publication) {
            if ($publication->getDoi() !== null) {
                $validPublishedPublicationsWithDoi[] = $publication;
            }
        }

        return $validPublishedPublicationsWithDoi;
    }

    /**
     * Get published chapters with a DOI assigned from chapter IDs.
     *
     * @param array $chapterIds
     * @param Context $context
     *
     * @return array
     */
    public function getPublishedChapters(array $chapterIds, Context $context): array
    {
        $validPublishedChapters = parent::getPublishedChapters($chapterIds, $context);
        $validPublishedChaptersWithDoi = [];
        /** @var Chapter $chapter */
        foreach ($validPublishedChapters as $chapter) {
            if ($chapter->getDoi() !== null) {
                $validPublishedChaptersWithDoi[] = $chapter;
            }
        }

        return $validPublishedChaptersWithDoi;
    }

    /**
     * Get publication formats from publication format IDs.
     *
     * @param array $publicationFormatIds
     * @param Context $context
     *
     * @return array
     */
    public function getPublishedPublicationFormats(array $publicationFormatIds, Context $context): array
    {
        $validPublishedPublicationFormats = parent::getPublishedPublicationFormats($publicationFormatIds, $context);
        $validPublishedPublicationFormatsWithDoi = [];
        /** @var PublicationFormat $publicationFormat */
        foreach ($validPublishedPublicationFormats as $publicationFormat) {
            if ($publicationFormat->getDoi() !== null) {
                $validPublishedPublicationFormatsWithDoi[] = $publicationFormat;
            }
        }

        return $validPublishedPublicationFormatsWithDoi;
    }


    /**
     * @copydoc ImportExportPlugin::executeCLI()
     */
    public function executeCLI($scriptName, &$args): void
    {
    }

    /**
     * @copydoc ImportExportPlugin::supportsCLI()
     */
    public function supportsCLI(): bool
    {
        return false;
    }

    public function createTestDOI(string $doi, string $testPrefix) : array|string|null
    {
        return PKPString::regexp_replace('#^[^/]+/#', $testPrefix . '/', $doi);
    }
}