<?php
namespace APP\plugins\generic\datacite;


use APP\core\Application;
use APP\core\Request;
use APP\facades\Repo;
use APP\monograph\Chapter;
use APP\plugins\generic\datacite\classes\DataciteSettings;
use APP\plugins\generic\datacite\classes\DOIPubIdExportPlugin;
use APP\publication\Publication;
use APP\publicationFormat\PublicationFormat;
use Exception;
use PKP\config\Config;
use PKP\context\Context;
use PKP\core\DataObject;
use PKP\core\PKPApplication;
use PKP\core\PKPString;
use PKP\doi\Doi;
use PKP\file\FileManager;
use PKP\file\TemporaryFileManager;
use PKP\submissionFile\SubmissionFile;

class DataciteExportPlugin extends DOIPubIdExportPlugin {
	#region constants
	//additional field names
	public const DEPOSIT_STATUS_FIELD_NAME = 'datacite-export::status';

	//status
	//private const EXPORT_STATUS_ANY = '';
	public const EXPORT_STATUS_NOT_DEPOSITED    = 'notDeposited';
	public const EXPORT_STATUS_MARKEDREGISTERED = 'markedRegistered';
	public const EXPORT_STATUS_REGISTERED       = 'registered';


    //DataCite API
    public const DATACITE_API_URL = 'https://mds.datacite.org/';
    public const DATACITE_API_URL_TEST = 'https://mds.test.datacite.org/';
	#endregion

    protected DatacitePlugin $agencyPlugin;

    public function __construct(DatacitePlugin $agencyPlugin)
    {
        parent::__construct();

        $this->agencyPlugin = $agencyPlugin;
    }

    public function getName(): string
    {
        return 'DataciteExportPlugin';
    }

    public function getDisplayName(): string
    {
        return __('plugins.importexport.datacite.displayName');
    }

    public function getDescription(): string
    {
        return __('plugins.importexport.datacite.description');
    }

    /**
     * @copydoc PubObjectsExportPlugin::getPublicationFilter()
     */
    public function getPublicationFilter(): string
    {
        return 'publication=>datacite-xml';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getChapterFilter()
     */
    public function getChapterFilter(): string
    {
        return 'chapter=>datacite-xml';
    }

    /**
     * @copydoc PubObjectsExportPlugin::getRepresentationFilter()
     */
    public function getRepresentationFilter(): string
    {
        return 'publicationFormat=>datacite-xml';
    }

    public function getPluginSettingsPrefix(): string
    {
        return 'datacite';
    }

    /**
     * @copydoc DOIPubIdExportPlugin::getSettingsFormClassName()
     */
    public function getSettingsFormClassName(): string
    {
        throw new Exception('DOI settings no longer managed via plugin settings form.');
    }

    /**
     * @copydoc PubObjectsExportPlugin::getExportDeploymentClassName()
     */
    public function getExportDeploymentClassName(): string
    {
        return '\APP\plugins\generic\datacite\DataciteExportDeployment';
    }

    public function getSetting($contextId, $name)
    {
        return $this->agencyPlugin->getSetting($contextId, $name);
    }

    /**
     * @param DataObject[] $objects
     *
     */
    public function exportAndDeposit(
        Context $context,
        array $objects,
        string &$responseMessage,
        ?bool $noValidation = null
    ): bool {
        $fileManager = new FileManager();

        $errorsOccurred = false;
        foreach ($objects as $object) {

            if ($this->isMarkedRegistered($object)) {
                continue;
            }

            /** @var Doi $doiObject */
            $doiObject = $object->getData('doiObject');
            $doiStatus = $doiObject->getStatus();
            if ($doiStatus == Doi::STATUS_STALE  || $doiStatus == Doi::STATUS_REGISTERED) {
                $isRedeposit = true;
            } else {
                $isRedeposit = false;
            }

            // Get the XML
            $exportErrors = [];
            $filter = $this->_getFilterFromObject($object);
            $exportXml = $this->exportXML($object, $filter, $context, $noValidation, $exportErrors);
            // Write the XML to a file.
            // export file name example: datacite-20160723-160036-articles-1-1.xml
            $objectFileNamePart = $this->_getObjectFileNamePart($object);
            $exportFileName = $this->getExportFileName($this->getExportPath(), $objectFileNamePart, $context, '.xml');
            $fileManager->writeFile($exportFileName, $exportXml);
            // Deposit the XML file.
            $result = $this->depositXML($object, $context, $exportFileName);
            if (!$result) {
                $errorsOccurred = true;
            }
            if (is_array($result)) {
                $resultErrors[] = $result;
            }
            // Remove all temporary files.
            $fileManager->deleteByPath($exportFileName);
        }
        // Prepare response message and return status
        if (empty($resultErrors)) {
            if ($errorsOccurred) {
                $responseMessage = 'plugins.generic.datacite.deposit.unsuccessful';
                return false;
            } else {
                $responseMessage = $this->getDepositSuccessNotificationMessageKey();
                return true;
            }
        } else {
            $responseMessage = 'api.dois.400.depositFailed';
            return false;
        }
    }

    /**
     * Exports and stores XML as a TemporaryFile
     *
     *
     * @throws Exception
     */
    public function exportAsDownload(Context $context, array $objects, ?bool $noValidation = null, ?array &$outputErrors = null): ?int
    {
        $fileManager = new TemporaryFileManager();

        // Export
        $result = $this->_checkForTar();
        if ($result === true) {
            $exportedFiles = [];
            $objectFileNamePart = '';
            foreach ($objects as $object) {
                $filter = $this->_getFilterFromObject($object);
                // Get the XML
                $exportXml = $this->exportXML($object, $filter, $context, $noValidation, $outputErrors);
                // Write the XML to a file.
                // export file name example: datacite-20160723-160036-books-1-1.xml
                $objectFileNamePart = $this->_getObjectFileNamePart($object);
                $exportFileName = $this->getExportFileName(
                    $this->getExportPath(),
                    $objectFileNamePart,
                    $context,
                    '.xml'
                );
                $fileManager->writeFile($exportFileName, $exportXml);
                $exportedFiles[] = $exportFileName;
            }
            // If we have more than one export file we package the files
            // up as a single tar before going on.
            assert(count($exportedFiles) >= 1);
            if (count($exportedFiles) > 1) {
                // tar file name: e.g. datacite-20160723-160036-books-1.tar.gz
                $finalExportFileName = $this->getExportFileName(
                    $this->getExportPath(),
                    $objectFileNamePart,
                    $context,
                    '.tar.gz'
                );
                $this->_tarFiles($this->getExportPath(), $finalExportFileName, $exportedFiles);
                // remove files
                foreach ($exportedFiles as $exportedFile) {
                    $fileManager->deleteByPath($exportedFile);
                }
            } else {
                $finalExportFileName = array_shift($exportedFiles);
            }
            $user = Application::get()->getRequest()->getUser();

            return $fileManager->createTempFileFromExisting($finalExportFileName, $user->getId());
        }

        return null;
    }

    /**
     * @param array|DataObject $objects
     * @param Context          $context
     * @param string           $filename
     * @param bool             $isRedeposit
     *
     * @return array|bool
     */
	public function depositXML(array|DataObject $objects, Context $context, string $filename, bool $isRedeposit = false): array|bool
    {
		if ($objects instanceof DataObject) {
            $object = $objects;
        } else {
            return false;
        }

        $request = Application::get()->getRequest();
        // Get the DOI and the URL for the object.
        $doi = $object->getStoredPubId('doi');
        assert(!empty($doi));
        $testDOIPrefix = null;
        if ($this->isTestMode($context)) {
            $testDOIPrefix = $this->getSetting($context->getId(), DataciteSettings::KEY_TEST_DOI_PREFIX);
            assert(!empty($testDOIPrefix));
            $doi = $this->createTestDOI($doi, $testDOIPrefix);
        }
        $url = $this->_getObjectUrl($request, $context, $object);
        assert(!empty($url));

        $dataCiteAPIUrl = self::DATACITE_API_URL;
        $username = $this->getSetting($context->getId(), 'username');
        $password = $this->getSetting($context->getId(), 'password');
        if ($this->isTestMode($context)) {
            $dataCiteAPIUrl = self::DATACITE_API_URL_TEST;
            $username = $this->getSetting($context->getId(), 'testUsername');
            $password = $this->getSetting($context->getId(), 'testPassword');
        }

        // Prepare HTTP session.
        assert(is_readable($filename));
        $httpClient = Application::get()->getHttpClient();
        try {
            $response = $httpClient->request('POST', $dataCiteAPIUrl . 'metadata', [
                'auth' => [$username, $password],
                'body' => fopen($filename, 'r'),
                'headers' => [
                    'Content-Type' => 'application/xml;charset=UTF-8',
                ],
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $returnMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $returnMessage = $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')';
            }
            $this->updateDepositStatus($object, Doi::STATUS_ERROR);
            return [['plugins.importexport.common.register.error.mdsError', "Registering DOI {$doi}: {$returnMessage}"]];
        }

        // Mint a DOI.
        $httpClient = Application::get()->getHttpClient();
        try {
            $response = $httpClient->request('POST', $dataCiteAPIUrl . 'doi', [
                'auth' => [$username, $password],
                'headers' => [
                    'Content-Type' => 'text/plain;charset=UTF-8',
                ],
                'body' => "doi={$doi}\nurl={$url}",
            ]);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            $returnMessage = $e->getMessage();
            if ($e->hasResponse()) {
                $returnMessage = $e->getResponse()->getBody() . ' (' . $e->getResponse()->getStatusCode() . ' ' . $e->getResponse()->getReasonPhrase() . ')';
            }
            $this->updateDepositStatus($object, Doi::STATUS_ERROR);
            return [['plugins.importexport.common.register.error.mdsError', "Registering DOI {$doi}: {$returnMessage}"]];
        }
        // Test mode submits entirely different DOI and URL so the status of that should not be stored in the database
        // for the real DOI
        if (!$this->isTestMode($context)) {
            $this->updateDepositStatus($object, Doi::STATUS_REGISTERED);
        }
        return true;
	}

    /**
     * Update stored DOI status based on if deposits and registration have been successful
     *
     * @param DataObject $object
     * @param string     $status
     */
    public function updateDepositStatus(DataObject $object, string $status): void
    {
        assert($object instanceof Publication || $object instanceof Chapter || $object instanceof PublicationFormat);
        $doiObject = $object->getData('doiObject');
        $editParams = [
            'status' => $status
        ];
        if ($status == Doi::STATUS_REGISTERED) {
            $editParams['registrationAgency'] = $this->getName();
        }
        Repo::doi()->edit($doiObject, $editParams);
    }

    /**
     * Test whether the tar binary is available.
     *
     * @return bool|array Boolean true if available otherwise
     *  an array with an error message.
     */
    public function _checkForTar(): bool|array
    {
        $tarBinary = Config::getVar('cli', 'tar');
        if (empty($tarBinary) || !is_executable($tarBinary)) {
            $result = [
                ['manager.plugins.tarCommandNotFound']
            ];
        } else {
            $result = true;
        }
        return $result;
    }

    /**
     * Create a tar archive.
     *
     * @param string $targetPath
     * @param string $targetFile
     * @param array $sourceFiles
     */
    public function _tarFiles(string $targetPath, string $targetFile, array $sourceFiles) : void
    {
        assert((bool) $this->_checkForTar());
        // GZip compressed result file.
        $tarCommand = Config::getVar('cli', 'tar') . ' -czf ' . escapeshellarg($targetFile);
        // Do not reveal our internal export path by exporting only relative filenames.
        $tarCommand .= ' -C ' . escapeshellarg($targetPath);
        // Do not reveal our webserver user by forcing root as owner.
        $tarCommand .= ' --owner 0 --group 0 --';
        // Add each file individually so that other files in the directory
        // will not be included.
        foreach ($sourceFiles as $sourceFile) {
            assert(dirname($sourceFile) . '/' === $targetPath);
            if (dirname($sourceFile) . '/' !== $targetPath) {
                continue;
            }
            $tarCommand .= ' ' . escapeshellarg(basename($sourceFile));
        }
        // Execute the command.
        exec($tarCommand);
    }

    /**
     * Get the canonical URL of an object.
     *
     * @param Request    $request
     * @param Context    $context
     * @param DataObject $object
     *
     * @return string|null
     */
    public function _getObjectUrl(Request$request, Context $context, DataObject $object): ?string
    {
        //Dispatcher needed when  called from CLI
        $dispatcher = $request->getDispatcher();
        $url = null;
        switch (true) {
            case $object instanceof Publication:
                $url = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'catalog', 'book', [$object->getData('submissionId')], null, null, true);
                break;
            case $object instanceof Chapter:
                $publication = Repo::publication()->get($object->getData('publicationId'));
                $url = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'catalog', 'book', [$publication->getData('submissionId'), 'chapter', $object->getSourceChapterId()], null, null, true);
                break;
            case $object instanceof PublicationFormat:
                $publication = Repo::publication()->get($object->getData('publicationId'));
                $submissionFiles = Repo::submissionFile()
                    ->getCollector()
                    ->filterBySubmissionIds([$publication->getData('submissionId')])
                    ->filterByFileStages([SubmissionFile::SUBMISSION_FILE_PROOF])
                    ->filterByGenreIds(['3']) //MANUSCRIPT
                    ->filterByAssoc(Application::ASSOC_TYPE_PUBLICATION_FORMAT, [$object->getId()])
                    ->getMany()
                    ->toArray();
                if (count($submissionFiles) > 0) {
                    /** @var SubmissionFile $submissionFile */
                    $submissionFile = array_shift($submissionFiles);
                    $url = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'catalog', 'view', [$publication->getData('submissionId'), $object->getId(), $submissionFile->getId()], null, null, true);
                } else {
                    $url = $dispatcher->url($request, PKPApplication::ROUTE_PAGE, $context->getPath(), 'catalog', 'book', [$publication->getData('submissionId')], null, null, true);
                }
                break;
        }

        if ($this->isTestMode($context)) {
            // Change server domain for testing.
            $url = PKPString::regexp_replace('#://[^\s]+/index.php#', '://example.com/index.php', $url);
        }
        return $url;
    }

    /**
     * @param DataObject $object
     *
     * @return string
     */
    private function _getFilterFromObject(DataObject $object): string
    {
        if ($object instanceof Publication) {
            return $this->getPublicationFilter();
        } elseif ($object instanceof Chapter) {
            return $this->getChapterFilter();
        } elseif ($object instanceof PublicationFormat) {
            return $this->getRepresentationFilter();
        } else {
            return '';
        }
    }

    private function _getObjectFileNamePart(DataObject $object): string
    {
        if ($object instanceof Publication) {
            return 'book-' . $object->getData('submissionId');
        } elseif ($object instanceof Chapter) {
            return 'chapter-' . $object->getSourceChapterId();
        } elseif ($object instanceof PublicationFormat) {
            return 'publicationFormat-' . $object->getId();
        } else {
            return '';
        }
    }

    public function isMarkedRegistered(DataObject $object): bool
    {
        /** @var Doi $doiObject */
        $doiObject = $object->getData('doiObject');
        $doiStatus = $doiObject->getStatus();
        if( $doiStatus == Doi::STATUS_REGISTERED && $doiObject->getData('registrationAgency') === null) {
            return true;
        }
        return false;
    }
}


