<?php

/**
 * @file plugins/generic/datacite/filter/DataciteXmlFilter.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DataciteXmlFilter
 *
 * @brief Class that converts a publication to a DataCite XML document.
 */

namespace APP\plugins\generic\datacite\filter;

use APP\author\Author;
use APP\core\Application;
use APP\facades\Repo;
use APP\monograph\Chapter;
use APP\plugins\generic\datacite\DataciteExportDeployment;
use APP\publication\Publication;
use APP\publicationFormat\PublicationFormat;
use DOMDocument;
use DOMElement;
use DOMException;
use PKP\context\Context;
use PKP\core\PKPString;
use PKP\filter\FilterGroup;
use PKP\i18n\LocaleConversion;
use PKP\plugins\importexport\native\filter\NativeExportFilter;

class DataciteXmlFilter extends NativeExportFilter
{
    // Title types
    public const DATACITE_TITLETYPE_TRANSLATED = 'TranslatedTitle';

    // Identifier types
    public const DATACITE_IDTYPE_DOI = 'DOI';
    public const DATACITE_IDTYPE_URL = 'URL';

    // Relation types
    public const DATACITE_RELTYPE_HASPART = 'HasPart';
    public const DATACITE_RELTYPE_ISPARTOF = 'IsPartOf';
    public const DATACITE_RELTYPE_ISPUBLISHEDIN = 'IsPublishedIn';

    // Description types
    public const DATACITE_DESCTYPE_ABSTRACT = 'Abstract';

    /**
     * Constructor
     *
     * @param FilterGroup $filterGroup
     */
    public function __construct($filterGroup)
    {
        $this->setDisplayName('DataCite XML export');
        parent::__construct($filterGroup);
    }

    //
    // Implement template methods from Filter
    //
    /**
     * @param mixed $input
     *
     * @return DOMDocument
     * @throws DOMException
     * @see Filter::process()
     */
    public function &process(mixed &$input): DOMDocument
    {
        // Create the XML document
        $doc = new DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
        /** @var DataciteExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $plugin = $deployment->getPlugin();
        $cache = $plugin->getCache();

        // Get all objects
        $publication = $chapter = $publicationFormat = null;
        if ($input instanceof Publication) {
            $publication = $input;
            if (!$cache->isCached('publication', $publication->getId())) {
                $cache->add($publication, null);
            }
        } elseif ($input instanceof Chapter) {
            $chapter = $input;
            $publication = Repo::publication()->get($chapter->getData('publicationId'));
            if (!$cache->isCached('publication', $publication->getId())) {
                $cache->add($publication, null);
            }
            if (!$cache->isCached('chapter', $chapter->getSourceChapterId())) {
                $cache->add($chapter, null);
            }
        } elseif ($input instanceof PublicationFormat) {
            $publicationFormat = $input;
            $publication = Repo::publication()->get($publicationFormat->getData('publicationId'));
            if (!$cache->isCached('publication', $publication->getId())) {
                    $cache->add($publication, null);
            }
            if (!$cache->isCached('publicationFormats', $publicationFormat->getId())) {
                $cache->add($publicationFormat, null);
            }
        }

        // Identify the object locale.
        $objectLocalePrecedence = $this->getObjectLocalePrecedence($context, $publication, $chapter, $publicationFormat);
        // The publisher is required.
        // Use the press name as DataCite recommends for now.
        $publisher = $context->getData('publisher');
        assert(!empty($publisher));
        // The publication date is required.
        if ($publication) {
            $publicationDate = $publication->getData('datePublished') ?: $publication->getData('dateSubmitted');
        }
        if ($chapter && $chapter->getDatePublished()) {
            $publicationDate = $chapter->getDatePublished();
        }
        assert(!empty($publicationDate));

        // Create the root node
        $rootNode = $this->createRootNode($doc);
        $doc->appendChild($rootNode);
        // DOI (mandatory)
        $doi = $input->getStoredPubId('doi');
        if ($plugin->isTestMode($context)) {
            $testDOIPrefix = $plugin->getSetting($context->getId(), 'testDOIPrefix');
            assert(!empty($testDOIPrefix));
            $doi = $plugin->createTestDOI($doi, $testDOIPrefix);
        }
        $rootNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'identifier', htmlspecialchars($doi, ENT_COMPAT, 'UTF-8')));
        $node->setAttribute('identifierType', self::DATACITE_IDTYPE_DOI);
        // Creators (mandatory)
        $rootNode->appendChild($this->createCreatorsNode($doc, $publication, $chapter, $publicationFormat, $publisher, $objectLocalePrecedence));
        // Title (mandatory)
        $rootNode->appendChild($this->createTitlesNode($doc, $publication, $chapter, $publicationFormat, $objectLocalePrecedence));
        // Publisher (mandatory)
        $rootNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'publisher', htmlspecialchars($publisher, ENT_COMPAT, 'UTF-8')));
        // Publication Year (mandatory)
        $rootNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'publicationYear', date('Y', strtotime($publicationDate))));
        // Subjects
        $subjects = [];
        if (!empty($publication)) {
            $subjects = array_merge(
                (array) $this->getPrimaryTranslation($publication->getData('keywords'), $objectLocalePrecedence),
                (array) $this->getPrimaryTranslation($publication->getData('subjects'), $objectLocalePrecedence)
            );
        }
        if (!empty($subjects)) {
            $subjectsNode = $doc->createElementNS($deployment->getNamespace(), 'subjects');
            foreach ($subjects as $subject) {
                $subjectsNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'subject', htmlspecialchars($subject, ENT_COMPAT, 'UTF-8')));
            }
            $rootNode->appendChild($subjectsNode);
        }
        // Language
        $rootNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'language', LocaleConversion::getIso1FromLocale($objectLocalePrecedence[0])));
        // Resource Type
        $resourceTypeNode = $this->createResourceTypeNode($doc, $publication, $chapter, $publicationFormat);
        $rootNode->appendChild($resourceTypeNode);
        // Related Identifiers
        $relatedIdentifiersNode = $this->createRelatedIdentifiersNode($doc, $publication, $chapter, $publicationFormat);
        if ($relatedIdentifiersNode) {
            $rootNode->appendChild($relatedIdentifiersNode);
        }
        // Rights
        $rightsURL = $publication ? $publication->getData('licenseUrl') : $context->getData('licenseUrl');
        if ($chapter) {
            $rightsURL = $chapter->getLicenseUrl() ?: $rightsURL;
        }
        if (!empty($rightsURL)) {
            $rightsNode = $doc->createElementNS($deployment->getNamespace(), 'rightsList');
            $rightsNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'rights', htmlspecialchars(strip_tags(Application::get()->getCCLicenseBadge($rightsURL)), ENT_COMPAT, 'UTF-8')));
            $node->setAttribute('rightsURI', $rightsURL);
            $rootNode->appendChild($rightsNode);
        }
        // Descriptions
        $descriptionsNode = $this->createDescriptionsNode($doc, $publication, $chapter, $publicationFormat, $objectLocalePrecedence);
        if ($descriptionsNode) {
            $rootNode->appendChild($descriptionsNode);
        }
        // relatedItems
        $relatedItemsNode = $this->createRelatedItemsNode($doc, $publication, $chapter, $publicationFormat, $publisher, $objectLocalePrecedence);
        if ($relatedItemsNode) {
            $rootNode->appendChild($relatedItemsNode);
        }

        return $doc;
    }

    //
    // Conversion functions
    //
    /**
     * Create and return the root node.
     *
     * @param DOMDocument $doc
     *
     * @return DOMElement
     */
    public function createRootNode(DOMDocument $doc): DOMElement
    {
        /** @var DataciteExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $rootNode = $doc->createElementNS($deployment->getNamespace(), $deployment->getRootElementName());
        $rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', $deployment->getXmlSchemaInstance());
        $rootNode->setAttribute('xsi:schemaLocation', $deployment->getNamespace() . ' ' . $deployment->getSchemaFilename());
        return $rootNode;
    }

    /**
     * Create creators node.
     *
     * @param DOMDocument            $doc
     * @param Publication            $publication
     * @param Chapter|null           $chapter
     * @param PublicationFormat|null $publicationFormat
     * @param string                 $publisher
     * @param array                  $objectLocalePrecedence
     *
     * @return DOMElement
     * @throws DOMException
     */
    public function createCreatorsNode(
        DOMDocument $doc,
        Publication $publication,
        ?Chapter $chapter,
        ?PublicationFormat $publicationFormat,
        string $publisher,
        array $objectLocalePrecedence
    ): DOMElement {
        /** @var DataciteExportDeployment $deployment*/
        $deployment = $this->getDeployment();
        $creators = [];
        if (null !== $chapter) {
            $chapterAuthors = $chapter->getAuthors()
                ->toArray();
            /** @var Author $author */
            foreach ($chapterAuthors as $author) {
                $creators[] = [
                    'name'        => $author->getFullName(false, true, $publication->getData('locale')),
                    'orcid'       => $author->getOrcid(),
                    'affiliation' => $author->getLocalizedData('affiliation', $publication->getData('locale')),
                    'ror'         => $author->getData('rorId') ?? null,
                ];
            }
        }
        if (empty($creators)) {
            $authors = $publication->getData('authors');
            assert(!empty($authors));
            /** @var Author $author */
            foreach ($authors as $author) {
                $creators[] = [
                    'name'        => $author->getFullName(false, true, $publication->getData('locale')),
                    'orcid'       => $author->getOrcid(),
                    'affiliation' => $author->getLocalizedData('affiliation', $publication->getData('locale')),
                    'ror'         => $author->getData('rorId') ?? null,
                ];
            }
        }
        assert(count($creators) >= 1);
        $creatorsNode = $doc->createElementNS($deployment->getNamespace(), 'creators');
        foreach ($creators as $creator) {
            $creatorNode = $doc->createElementNS($deployment->getNamespace(), 'creator');
            $creatorNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'creatorName', htmlspecialchars($creator['name'], ENT_COMPAT, 'UTF-8')));
            if ($creator['orcid']) {
                $node = $doc->createElementNS($deployment->getNamespace(), 'nameIdentifier');
                $node->appendChild($doc->createTextNode($creator['orcid']));
                $node->setAttribute('schemeURI', 'http://orcid.org/');
                $node->setAttribute('nameIdentifierScheme', 'ORCID');
                $creatorNode->appendChild($node);
            }
            if ($creator['affiliation']) {
                $node = $doc->createElementNS($deployment->getNamespace(), 'affiliation');
                if ($creator['ror']) {
                    $node->setAttribute('affiliationIdentifier', $creator['ror']);
                    $node->setAttribute('affiliationIdentifierScheme', 'ROR');
                    $node->setAttribute('schemeURI', 'https://ror.org');
                }
                $node->appendChild($doc->createTextNode($creator['affiliation']));
                $creatorNode->appendChild($node);
            }
            $creatorsNode->appendChild($creatorNode);
        }
        return $creatorsNode;
    }

    /**
     * Create titles node.
     *
     * @param DOMDocument            $doc
     * @param Publication            $publication
     * @param Chapter|null           $chapter
     * @param PublicationFormat|null $publicationFormat
     * @param array                  $objectLocalePrecedence
     *
     * @return DOMElement
     * @throws DOMException
     */
    public function createTitlesNode(
        DOMDocument $doc,
        Publication $publication,
        ?Chapter $chapter,
        ?PublicationFormat $publicationFormat,
        array $objectLocalePrecedence
    ): DOMElement {
        /** @var DataciteExportDeployment $deployment*/
        $deployment = $this->getDeployment();
        $plugin = $deployment->getPlugin();
        // Get an array of localized titles.
        $publicationFormatNames = [];
        if (null !==  $publicationFormat) {
            $publicationFormatNames = $publicationFormat->getName($objectLocalePrecedence);
            $publicationFormatNames = $this->getTranslationsByPrecedence($publicationFormatNames, $objectLocalePrecedence);
        }
        $chapterTitles = [];
        if (null !== $chapter) {
            $chapterTitles = $chapter->getFullTitles();
            $chapterTitles = $this->getTranslationsByPrecedence($chapterTitles, $objectLocalePrecedence);
        }
        $publicationTitles = $publication->getTitles();

        // Order titles by locale precedence.
        $publicationTitles = $this->getTranslationsByPrecedence($publicationTitles, $objectLocalePrecedence);

        // We expect at least one title.
        $counter = count($publicationFormatNames) + count($chapterTitles) + count($publicationTitles);
        assert($counter >= 1);
        $titlesNode = $doc->createElementNS($deployment->getNamespace(), 'titles');
        // Start with the primary object locale.
        $primaryPublicationTitle = array_shift($publicationTitles);
        $primaryChapterTitle = array_shift($chapterTitles);
        $primaryPublicationFormatName = array_shift($publicationFormatNames);

        if ($primaryPublicationFormatName) {
            $titlesNode->appendChild(
                $node = $doc->createElementNS(
                    $deployment->getNamespace(),
                    'title',
                    htmlspecialchars(
                        PKPString::html2text($primaryPublicationTitle . ' (' . $primaryPublicationFormatName . ')'),
                        ENT_COMPAT,
                        'UTF-8'
                    )
                )
            );
            // Then let the translated titles follow.
            foreach ($publicationTitles as $locale => $title) {
                $publicationFormatName = $publicationFormatNames[$locale] ?: $primaryPublicationFormatName;
                $titlesNode->appendChild(
                    $node = $doc->createElementNS(
                        $deployment->getNamespace(),
                        'title',
                        htmlspecialchars(
                            PKPString::html2text($title . ' (' . $publicationFormatName . ')'),
                            ENT_COMPAT,
                            'UTF-8'
                        )
                    )
                );
                $node->setAttribute('titleType', self::DATACITE_TITLETYPE_TRANSLATED);
            }
        } elseif ($primaryChapterTitle) {
            $titlesNode->appendChild(
                $node = $doc->createElementNS(
                    $deployment->getNamespace(),
                    'title',
                    htmlspecialchars(
                        PKPString::html2text($primaryChapterTitle),
                        ENT_COMPAT,
                        'UTF-8'
                    )
                )
            );
            // Then let the translated titles follow.
            foreach ($chapterTitles as $locale => $title) {
                $titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'title', htmlspecialchars(PKPString::html2text($title), ENT_COMPAT, 'UTF-8')));
                $node->setAttribute('titleType', self::DATACITE_TITLETYPE_TRANSLATED);
            }
        } else {
            $titlesNode->appendChild(
                $node = $doc->createElementNS(
                    $deployment->getNamespace(),
                    'title',
                    htmlspecialchars(
                        PKPString::html2text($primaryPublicationTitle),
                        ENT_COMPAT,
                        'UTF-8'
                    )
                )
            );
            // Then let the translated titles follow.
            foreach ($publicationTitles as $locale => $title) {
                $titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'title', htmlspecialchars(PKPString::html2text($title), ENT_COMPAT, 'UTF-8')));
                $node->setAttribute('titleType', self::DATACITE_TITLETYPE_TRANSLATED);
            }
        }

        return $titlesNode;
    }

    /**
     * Create a resource type node.
     *
     * @param DOMDocument            $doc
     * @param Publication            $publication
     * @param Chapter|null           $chapter
     * @param PublicationFormat|null $publicationFormat
     *
     * @return DOMElement
     * @throws DOMException
     */
    public function createResourceTypeNode(
        DOMDocument $doc,
        Publication $publication,
        ?Chapter $chapter,
        ?PublicationFormat $publicationFormat
    ): DOMElement {
        /** @var DataciteExportDeployment $deployment */
        $deployment = $this->getDeployment();
        switch (true) {
            case null !== $chapter:
                $resourceType = 'Chapter';
                break;
            default:
                $resourceType = 'Monograph';
        }
        if ($resourceType === 'Chapter') {
            // Create the resourceType element for Chapters.
            $resourceTypeNode = $doc->createElementNS($deployment->getNamespace(), 'resourceType', $resourceType);
            $resourceTypeNode->setAttribute('resourceTypeGeneral', 'BookChapter');
        } else {
            $resourceTypeNode = $doc->createElementNS($deployment->getNamespace(), 'resourceType', $resourceType);
            $resourceTypeNode->setAttribute('resourceTypeGeneral', 'Book');
        }
        return $resourceTypeNode;
    }

    /**
     * Generate related identifiers node list.
     *
     * @param DOMDocument            $doc
     * @param Publication            $publication
     * @param Chapter|null           $chapter
     * @param PublicationFormat|null $publicationFormat
     *
     * @return ?DOMElement
     * @throws DOMException
     */
    public function createRelatedIdentifiersNode(
        DOMDocument $doc,
        Publication $publication,
        ?Chapter $chapter,
        ?PublicationFormat $publicationFormat
    ): ?DOMElement {
        $deployment = $this->getDeployment();
        $relatedIdentifiersNode = $doc->createElementNS($deployment->getNamespace(), 'relatedIdentifiers');
        switch (true) {
            case null !== $publicationFormat:
            case null !== $chapter:
                assert(isset($publication));
                $doi = $publication->getDoi();
                if (!empty($doi)) {
                    $relatedIdentifiersNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'relatedIdentifier', htmlspecialchars($doi, ENT_COMPAT, 'UTF-8')));
                    $node->setAttribute('relatedIdentifierType', self::DATACITE_IDTYPE_DOI);
                    $node->setAttribute('relationType', self::DATACITE_RELTYPE_ISPARTOF);
                }
                break;
            default:
                // Chapters
                $chapters = $publication->getData('chapters');
                foreach ($chapters as $chap) {
                    $doi = $chap->getDoi();
                    if (!empty($doi)) {
                        $relatedIdentifiersNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'relatedIdentifier', htmlspecialchars($doi, ENT_COMPAT, 'UTF-8')));
                        $node->setAttribute('relatedIdentifierType', self::DATACITE_IDTYPE_DOI);
                        $node->setAttribute('relationType', self::DATACITE_RELTYPE_HASPART);
                    }
                    unset($chap, $doi);
                }

                // Publication formats
                $publicationFormats = $publication->getData('publicationFormats');
                foreach ($publicationFormats as $pubFormat) {
                    $doi = $pubFormat->getDoi();
                    if (!empty($doi)) {
                        $relatedIdentifiersNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'relatedIdentifier', htmlspecialchars($doi, ENT_COMPAT, 'UTF-8')));
                        $node->setAttribute('relatedIdentifierType', self::DATACITE_IDTYPE_DOI);
                        $node->setAttribute('relationType', self::DATACITE_RELTYPE_HASPART);
                    }
                    unset($pubFormat, $doi);
                }
                break;
        }
        if ($relatedIdentifiersNode->hasChildNodes()) {
            return $relatedIdentifiersNode;
        } else {
            return null;
        }
    }

    /**
     * Create descriptions node list.
     *
     * @param DOMDocument            $doc
     * @param Publication            $publication
     * @param Chapter|null           $chapter
     * @param PublicationFormat|null $publicationFormat
     * @param array                  $objectLocalePrecedence
     *
     * @return ?DOMElement Can be null if a size cannot be identified for the given object.
     * @throws DOMException
     */
    public function createDescriptionsNode(
        DOMDocument $doc,
        Publication $publication,
        ?Chapter $chapter,
        ?PublicationFormat $publicationFormat,
        array $objectLocalePrecedence
    ): ?DOMElement {
        /** @var DataciteExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $plugin = $deployment->getPlugin();
        $descriptions = [];
        $descriptions = $chapter ? $chapter->getData('abstract') : $publication->getData('abstract');
        $descriptions = $this->getPrimaryTranslation($descriptions, $objectLocalePrecedence);

        $descriptionsNode = null;
        if (!empty($descriptions)) {
            $descriptionsNode = $doc->createElementNS($deployment->getNamespace(), 'descriptions');
            foreach ($descriptions as $description) {
                $descriptionsNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'description', htmlspecialchars(PKPString::html2text($description), ENT_COMPAT, 'UTF-8')));
                $node->setAttribute('descriptionType', self::DATACITE_DESCTYPE_ABSTRACT);
            }
        }
        return $descriptionsNode;
    }

    /**
     * Create related items node.
     *
     * @param DOMDocument            $doc
     * @param Publication            $publication
     * @param Chapter|null           $chapter
     * @param PublicationFormat|null $publicationFormat
     * @param string                 $publisher
     * @param array                  $objectLocalePrecedence
     *
     * @return ?DOMElement Can be null if a size cannot be identified for the given object.
     * @throws DOMException
     */
    public function createRelatedItemsNode(
        DOMDocument $doc,
        Publication $publication,
        ?Chapter $chapter,
        ?PublicationFormat $publicationFormat,
        string $publisher,
        array $objectLocalePrecedence
    ): ?DOMElement {
        /** @var DataciteExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $plugin = $deployment->getPlugin();
        $request = Application::get()->getRequest();

        $relatedItemsNode = null;
        if (null !== $chapter) {
            $relatedItemsNode = $doc->createElementNS($deployment->getNamespace(), 'relatedItems');

            $relatedItemNode = $doc->createElementNS($deployment->getNamespace(), 'relatedItem');
            $relatedItemNode->setAttribute('relationType', self::DATACITE_RELTYPE_ISPUBLISHEDIN);
            $relatedItemNode->setAttribute('relatedItemType', 'Book');
            $url = $plugin->_getObjectUrl( $request, $context, $publication);
            $relatedItemIdentifierNode = $doc->createElementNS($deployment->getNamespace(), 'relatedItemIdentifier', $url);
            $relatedItemIdentifierNode->setAttribute('relatedItemIdentifierType', self::DATACITE_IDTYPE_URL);
            $relatedItemNode->appendChild($relatedItemIdentifierNode);

            $publicationTitles = $publication->getTitles();
            // Order titles by locale precedence.
            $publicationTitles = $this->getTranslationsByPrecedence($publicationTitles, $objectLocalePrecedence);
            // Start with the primary object locale.
            $primaryPublicationTitle = array_shift($publicationTitles);
            $titlesNode = $doc->createElementNS($deployment->getNamespace(), 'titles');
            $titleNode = $doc->createElementNS($deployment->getNamespace(), 'title');
            $titleNode->appendChild(
                $node = $doc->createTextNode(
                    htmlspecialchars(
                        PKPString::html2text($primaryPublicationTitle),
                        ENT_COMPAT,
                        'UTF-8'
                    )
                )
            );
            $titlesNode->appendChild($titleNode);
            // Then let the translated titles follow.
            foreach ($publicationTitles as $locale => $title) {
                $titleNode = $doc->createElementNS($deployment->getNamespace(), 'title');
                $titleNode->appendChild(
                    $node = $doc->createTextNode(
                        htmlspecialchars(
                            PKPString::html2text($title),
                            ENT_COMPAT,
                            'UTF-8'
                        )
                    )
                );
                $titleNode->setAttribute('titleType', self::DATACITE_TITLETYPE_TRANSLATED);
                $titlesNode->appendChild($titleNode);
            }
            $relatedItemNode->appendChild($titlesNode);
            $relatedItemsNode->appendChild($relatedItemNode);
        }
        return $relatedItemsNode;
    }


    //
    // Helper functions
    //
    /**
     * Identify the locale precedence for this export.
     *
     * @param Context                $context
     * @param Publication|null       $publication
     * @param Chapter|null           $chapter
     * @param PublicationFormat|null $publicationFormat
     *
     * @return array A list of valid PKP locales in descending
     *  order of priority.
     */
    public function getObjectLocalePrecedence(
        Context $context,
        ?Publication $publication,
        ?Chapter $chapter,
        ?PublicationFormat $publicationFormat
    ): array {
        $locales = [];
        if ($publication instanceof Publication) {
            if (!is_null($publication->getData('locale'))) {
                $locales[] = $publication->getData('locale');
            }
        }
        // Use the press locale as fallback.
        $locales[] = $context->getPrimaryLocale();
        // Use form locales as fallback.
        $formLocales = $context->getSupportedFormLocales();
        // Sort form locales alphabetically so that
        // we get a well-defined order.
        sort($formLocales);
        foreach ($formLocales as $formLocale) {
            if (!in_array($formLocale, $locales)) {
                $locales[] = $formLocale;
            }
        }
        assert(!empty($locales));
        return $locales;
    }

    /**
     * Identify the primary translation from an array of
     * localized data.
     *
     * @param array $localizedData An array of localized
     *  data (key: locale, value: localized data).
     * @param array $localePrecedence An array of locales
     *  by descending priority.
     *
     * @return mixed|null The value of the primary locale
     *  or null if no primary translation could be found.
     */
    public function getPrimaryTranslation(array $localizedData, array $localePrecedence): mixed
    {
        // Check whether we have localized data at all.
        if (empty($localizedData)) {
            return null;
        }
        // Try all locales from the precedence list first.
        foreach ($localePrecedence as $locale) {
            if (!empty($localizedData[$locale])) {
                return $localizedData[$locale];
            }
        }
        // As a fallback: use any translation by alphabetical
        // order of locales.
        ksort($localizedData);
        foreach ($localizedData as $locale => $value) {
            if (!empty($value)) {
                return $value;
            }
        }
        // If we found nothing (how that?) return null.
        return null;
    }

    /**
     * Re-order localized data by locale precedence.
     *
     * @param array $localizedData An array of localized
     *  data (key: locale, value: localized data).
     * @param array $localePrecedence An array of locales
     *  by descending priority.
     *
     * @return array Re-ordered localized data.
     */
    public function getTranslationsByPrecedence(array $localizedData, array $localePrecedence): array
    {
        $reorderedLocalizedData = [];

        // Check whether we have localized data at all.
        if (empty($localizedData)) {
            return $reorderedLocalizedData;
        }

        // Order by explicit locale precedence first.
        foreach ($localePrecedence as $locale) {
            if (!empty($localizedData[$locale])) {
                $reorderedLocalizedData[$locale] = $localizedData[$locale];
            }
            unset($localizedData[$locale]);
        }

        // Order any remaining values alphabetically by locale
        // and amend the re-ordered array.
        ksort($localizedData);
        return array_merge($reorderedLocalizedData, $localizedData);
    }
}
