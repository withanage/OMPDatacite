<?php
/** @defgroup plugins_generic_datacite DataCite export plugin */

/**
 * @file    plugins/generic/datacite/DataciteExportDeployment.php*
 * @class   DataciteExportDeployment
 * @ingroup plugins_importexport_datacite*
 * @brief   Base class configuring the datacite export process to an
 * application's specifics.
 */
namespace APP\plugins\generic\datacite;

use APP\author\Author;
use APP\monograph\Chapter;
use APP\publication\Publication;
use APP\publicationFormat\PublicationFormat;
use DOMDocument;
use DOMElement;
use Exception;
use PKP\core\DataObject;
use PKP\core\PKPRequest;
use PKP\plugins\importexport\PKPImportExportDeployment;
use PKP\xml\XMLNode;


class DataciteExportDeployment extends PKPImportExportDeployment {

    public const DATACITE_XMLNS = 'http://datacite.org/schema/kernel-4';
    public const DATACITE_XSI_SCHEMA_LOCATION = 'http://schema.datacite.org/meta/kernel-4.3/metadata.xsd';
    public const XMLNS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';

	/** @var DataciteExportPlugin $plugin */
	private DataciteExportPlugin $plugin;

	/**
	 * DataciteExportDeployment constructor.
	 *
	 * @param PKPRequest $request
	 * @param DataciteExportPlugin $plugin
	 */
	public function __construct(PKPRequest $request, DataciteExportPlugin $plugin) {
		$context = $request->getContext();
		parent::__construct($context);
		$this->plugin = $plugin;
	}


    public function getNamespace(): string
    {
        return self::DATACITE_XMLNS;
    }

    private function getRootElementName(): string
    {
        return 'resource';
    }

    private function getXmlSchemaInstance(): string
    {
        return self::XMLNS_XSI;
    }

    private function getSchemaLocation(): string
    {
        return self::DATACITE_XSI_SCHEMA_LOCATION;
    }

    private function xmlEscape($value) : string {
        return XMLNode::xmlentities($value, ENT_NOQUOTES);
    }

	public function createNodes(DOMDocument $documentNode, DataObject $object, null|Publication $parent): DOMDocument
    {
		$isSubmission = $parent === null;
        $documentNode = $this->createRootNode($documentNode);
		$documentNode = $this->createResourceType($documentNode, $object);
		$documentNode = $this->createResourceIdentifier($documentNode, $object);
		$documentNode = $this->createTitles($documentNode, $object, $parent, $isSubmission);
		$documentNode = $this->createAuthors($documentNode, $object, $parent, $isSubmission);
		$documentNode = $this->createPublicationYear($documentNode, $object, $parent, $isSubmission);
        return $this->createPublisher($documentNode);
	}

	private function createRootNode(DOMDocument $documentNode): DOMDocument
    {
		$rootNode = $documentNode->createElementNS($this->getNamespace(), $this->getRootElementName());
		$rootNode->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', $this->getXmlSchemaInstance());
		$rootNode->setAttribute('xsi:schemaLocation', $this->getNamespace() . ' ' . $this->getSchemaLocation());
		$documentNode->appendChild($rootNode);

		return $documentNode;
	}

	private function createResourceType(DOMDocument $documentNode, DataObject $object): DOMDocument
    {
        if ($object instanceof Publication) {
            $type = 'Monograph';
        } elseif ($object instanceof Chapter) {
            $type = 'Chapter';
        } elseif ($object instanceof PublicationFormat) {
            $type = 'Publication Format';
        } else {
            $type = 'Text';
        }
        $e = $documentNode->createElement('resourceType', $type);
        $e->setAttribute('resourceTypeGeneral', 'Text');
        $documentNode->documentElement->appendChild($e);

		return $documentNode;
	}

	private function createResourceIdentifier(DOMDocument $documentNode, DataObject $object): DOMDocument
    {
		$doi = $object->getDOI();

		if (isset($doi) && $this->plugin->isTestMode($this->getContext())) {
			$doi = preg_replace(
				'/^[\d]+(.)[\d]+/',
				$this->plugin->getDataciteAPITestPrefix($this->getContext()),
				$doi
			);
		}

        $identifier = $documentNode->createElement('identifier', $doi);
        $identifier->setAttribute('identifierType', 'DOI');
        $documentNode->documentElement->appendChild($identifier);

		return $documentNode;
	}

	private function createTitles(DOMDocument $documentNode, DataObject $object, null|Publication $parent, bool $isSubmission): DOMDocument
    {
		$locale = $isSubmission ? $object->getData('locale') : $parent->getData('locale');
		if($object instanceof PublicationFormat) {
            $localizedTitle = $parent->getLocalizedTitle($locale);
            $localizedTitle .= ' - ' . $object->getName($locale);
        } else {
            $localizedTitle = $object->getLocalizedTitle($locale);
        }

		$titles = $documentNode->createElement('titles');
		$titleValue = $this->xmlEscape($localizedTitle);

        $title = $documentNode->createElement('title', $titleValue);
        $pos = strpos($locale, '_');
        if ($pos !== FALSE) {
            $locale = substr_replace($locale, '-', $pos, strlen('_'));
        }
        $title->setAttribute('xml:lang', $locale);
        $titles->appendChild($title);

		$documentNode->documentElement->appendChild($titles);

		return $documentNode;
	}

	private function createAuthors(DOMDocument $documentNode, DataObject $object, null|Publication $parent, bool $isSubmission): DOMDocument
    {
        $locale = $isSubmission ? $object->getData('locale') : $parent->getData('locale');
		$creators = $documentNode->createElement('creators');
		if ($isSubmission === TRUE) {
			/** @var Publication $authors */
			$authors = $object->getData('authors');
			foreach ($authors as $author) {
				$creator = $this->createAuthor($documentNode, $author, $locale);
				if ($creator) {
					$creators->appendChild($creator);
					$documentNode->documentElement->appendChild($creators);
				}
			}
		} elseif ($object instanceof PublicationFormat) {
            /** @var Publication $authors */
            $authors = $parent->getData('authors');
            foreach ($authors as $author) {
                $creator = $this->createAuthor($documentNode, $author, $locale);
                if ($creator) {
                    $creators->appendChild($creator);
                    $documentNode->documentElement->appendChild($creators);
                }
            }
        } else {
			/** @var Chapter $object */
			$chapterAuthors = $object->getAuthors()->toArray();
			try {
                foreach ( $chapterAuthors as $author ) {
					$creator = $this->createAuthor($documentNode, $author, $locale);
					if ($creator) {
						$creators->appendChild($creator);
						$documentNode->documentElement->appendChild($creators);
					}
				}
			}
			catch (Exception $e) {
				DataciteExportPlugin::writeLog($e, 'ERROR');
			}
		}

		return $documentNode;
	}

	private function createAuthor(DOMDocument $documentNode, Author $author, string $locale) : bool|DOMElement|null
    {
		$creator = $documentNode->createElement('creator');
		$familyName = $author->getFamilyName($locale);
		$givenName = $author->getGivenName($locale);

		if (empty($familyName) && empty($givenName)) {
			return NULL;
		}

        $creatorName = $documentNode->createElement('creatorName', $familyName . ', ' . $givenName);
        $creatorName->setAttribute('nameType', 'Personal');
        $creator->appendChild($creatorName);

		return $creator;
	}

	private function createPublicationYear(DOMDocument $documentNode, DataObject$object, null|Publication$parent, bool $isSubmission): DOMDocument
    {
        $date = $object->getData('datePublished');
        if ($object instanceof Publication) {
            if (NULL === $date) {
                $date = $object->getData('dateSubmitted');
            }
        } elseif ($object instanceof Chapter) {
            if (NULL === $date) {
                    $date = $parent->getData('datePublished') ?: $parent->getData('dateSubmitted');
            }
        } elseif ($object instanceof PublicationFormat) {
            $date = $parent->getData('datePublished') ?: $parent->getData('dateSubmitted');
        }

        $publicationYear = $documentNode->createElement('publicationYear', substr($date, 0, 4));
        $documentNode->documentElement->appendChild($publicationYear);

		return $documentNode;
	}

	private function createPublisher($documentNode) {
        $publisher = $documentNode->createElement('publisher', $this->getContext()->getData('publisher'));

		$documentNode->documentElement->appendChild($publisher);

		return $documentNode;
	}
}
