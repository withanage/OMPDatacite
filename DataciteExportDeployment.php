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

use APP\plugins\generic\datacite\classes\DOIPubIdExportPlugin;
use APP\plugins\generic\datacite\classes\PubObjectCache;
use PKP\context\Context;
use PKP\plugins\importexport\PKPImportExportDeployment;


class DataciteExportDeployment extends PKPImportExportDeployment
{
    // XML attributes
    public const DATACITE_XMLNS = 'http://datacite.org/schema/kernel-4';
    public const DATACITE_XMLNS_XSI = 'http://www.w3.org/2001/XMLSchema-instance';
    public const DATACITE_XSI_SCHEMAVERSION = '4';
    public const DATACITE_XSI_SCHEMALOCATION = 'http://schema.datacite.org/meta/kernel-4/metadata.xsd';

    /** @var DataciteExportPlugin $_plugin The current import/export plugin */
    public DataciteExportPlugin $_plugin;

    /**
     * Get the plugin cache
     *
     * @return PubObjectCache
     */
    public function getCache(): PubObjectCache
    {
        return $this->_plugin->getCache();
    }

    /**
     * Constructor
     *
     * @param Context $context
     * @param DOIPubIdExportPlugin $plugin
     */
    public function __construct($context, $plugin)
    {
        parent::__construct($context);
        $this->setPlugin($plugin);
    }

    //
    // Deployment items for subclasses to override
    //
    /**
     * Get the root element name
     *
     * @return string
     */
    public function getRootElementName(): string
    {
        return 'resource';
    }

    /**
     * Get the namespace URN
     *
     * @return string
     */
    public function getNamespace(): string
    {
        return self::DATACITE_XMLNS;
    }

    /**
     * Get the schema instance URN
     *
     * @return string
     */
    public function getXmlSchemaInstance(): string
    {
        return self::DATACITE_XMLNS_XSI;
    }

    /**
     * Get the schema version
     *
     * @return string
     */
    public function getXmlSchemaVersion(): string
    {
        return self::DATACITE_XSI_SCHEMAVERSION;
    }

    /**
     * Get the schema location URL
     *
     * @return string
     */
    public function getXmlSchemaLocation(): string
    {
        return self::DATACITE_XSI_SCHEMALOCATION;
    }

    /**
     * Get the schema filename.
     *
     * @return string
     */
    public function getSchemaFilename(): string
    {
        return $this->getXmlSchemaLocation();
    }

    //
    // Getter/setters
    //
    /**
     * Set the import/export plugin.
     *
     * @param DataciteExportPlugin $plugin
     *
     * @return self
     */
    public function setPlugin(DataciteExportPlugin $plugin): self
    {
        $this->_plugin = $plugin;
        return $this;
    }

    /**
     * Get the import/export plugin.
     *
     * @return DataciteExportPlugin
     */
    public function getPlugin(): DataciteExportPlugin
    {
        return $this->_plugin;
    }
}
