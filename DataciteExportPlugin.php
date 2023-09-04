<?php
namespace APP\plugins\generic\datacite;


use APP\core\Application;
use APP\facades\Repo;
use APP\monograph\Chapter;
use APP\notification\NotificationManager;
use APP\plugins\generic\datacite\classes\DataciteSettings;
use APP\publication\Publication;
use APP\publicationFormat\PublicationFormat;
use APP\submission\Submission;
use DateTime;
use DOMDocument;
use JsonException;
use PKP\config\Config;
use PKP\context\Context;
use PKP\core\DataObject;
use PKP\core\PKPString;
use PKP\doi\Doi;
use PKP\file\FileManager;
use PKP\file\TemporaryFileManager;
use PKP\notification\PKPNotification;
use PKP\plugins\ImportExportPlugin;

class DataciteExportPlugin extends ImportExportPlugin {
	#region constants
	//additional field names
	public const DEPOSIT_STATUS_FIELD_NAME = 'datacite-export::status';

	//status
	//private const EXPORT_STATUS_ANY = '';
	public const EXPORT_STATUS_NOT_DEPOSITED    = 'notDeposited';
	public const EXPORT_STATUS_MARKEDREGISTERED = 'markedRegistered';
	public const EXPORT_STATUS_REGISTERED       = 'registered';

	//notifications
	private const RESPONSE_KEY_STATUS                = 'status';
	private const RESPONSE_KEY_MESSAGE               = 'message';
	private const RESPONSE_KEY_TITLE                 = 'title';
	private const RESPONSE_KEY_ACTION                = 'action';
	private const RESPONSE_KEY_TYPE                  = 'type';
	private const RESPONSE_MESSAGE_MARKED_REGISTERED = 'Marked registered';
	private const RESPONSE_OBJECT_TYPE_SUBMISSION    = 'Submission';
	private const RESPONSE_OBJECT_TYPE_CHAPTER       = 'Chapter';
    private const RESPONSE_OBJECT_TYPE_PUBLICATION_FORMAT = 'PublicationFormat';
	private const RESPONSE_ACTION_MARKREGISTERED     = 'mark registered';
	private const RESPONSE_ACTION_DEPOSIT            = 'deposit';
	private const RESPONSE_ACTION_REDEPOSIT          = 'redposit';

    //DataCite API
    private const DATACITE_API_URL = 'https://mds.datacite.org/';
    private const DATACITE_API_URL_TEST = 'https://mds.test.datacite.org/';

	//API response
	private const DATACITE_API_RESPONSE_OK                         = array(200, 201);
	private const DATACITE_API_RESPONSE_DOI_HAS_ALREADY_BEEN_TAKEN = array(422, 'This DOI has already been taken');
	#endregion

    private DatacitePlugin $_agencyPlugin;

    public function __construct( DatacitePlugin $agencyPlugin )
    {
        parent::__construct();
        $this->_agencyPlugin = $agencyPlugin;
    }

    public function register($category, $path, $mainContextId = NULL) : bool
    {
        $success = parent::register($category, $path, $mainContextId);
        if ($success) {
            if (Application::isUnderMaintenance()) {
                return true;
            }
        }
        return $success;
    }

    public function getName() : string
    {

        return 'DataciteExportPlugin';
    }

    public function getDisplayName() : string
    {
        return __('plugins.importexport.datacite.displayName');
    }

    public function getDescription() : string
    {

        return __( 'plugins.importexport.datacite.description' );
    }

    public function getPluginSettingsPrefix(): string
    {
        return 'datacite';
    }

    public function usage($scriptName) : void {
        fatalError('Not implemented.');
    }

    public function executeCLI(		$scriptName, &$args) : void
    {
        fatalError('Not implemented.');
    }

    public function getSetting($contextId, $name)
    {
        return $this->_agencyPlugin->getSetting($contextId, $name);
    }

	public function isTestMode($context) : bool
    {
        return ($this->getSetting($context->getId(), DataciteSettings::KEY_TEST_MODE) == 1);
	}

    public function getDataciteAPITestPrefix(Context $context) {
        return $this->getSetting( $context->getId(), DataciteSettings::KEY_TEST_DOI_PREFIX);
    }

    /**
     * @param DataObject $object
     * @param Context    $context
     * @param string     $filename
     * @param bool       $isRedeposit
     *
     * @return mixed
     */
	public function depositXML(DataObject $object, Context $context, string $filename, bool $isRedeposit = false): array
    {
		$request = Application::get()->getRequest();
        $doi = $object->getDoi();

		if ($object instanceof Publication) {
			$url = $request->url(
				$context->getPath(),
				'catalog',
				'book',
				[$object->getData('submissionId')]
			);
		} elseif ($object instanceof Chapter) {
            $publication = Repo::publication()->get($object->getData('publicationId'));
			$url = $request->url(
				$context->getPath(),
				'catalog',
				'book',
				[$publication->getData('submissionId'), 'chapter', $object->getSourceChapterId()]
			);
		} elseif ($object instanceof PublicationFormat) {
            $publication = Repo::publication()->get($object->getData('publication_id'));
            $url = $request->url(
                $context->getPath(),
                'catalog',
                'book',
                [$publication->getData('submissionId')]
            );
        }

        assert(!empty($url));
        $curlCh = curl_init();

		assert(!empty($doi));
		if ($this->isTestMode($context)) {
			$doi = $this->createTestDOI($doi, $context);
            $username = $this->getSetting($context->getId(), DataciteSettings::KEY_TEST_USERNAMER);
            $dataCiteAPIUrl = self::DATACITE_API_URL_TEST;
            $password = $this->getSetting($context->getId(), DataciteSettings::KEY_TEST_PASSWORD);
		} else {
            $username = $this->getSetting($context->getId(), DataciteSettings::KEY_USERNAME);
            $dataCiteAPIUrl = self::DATACITE_API_URL;
            $password = $this->getSetting($context->getId(), DataciteSettings::KEY_PASSWORD);
        }

		if ($httpProxyHost = Config::getVar('proxy', 'http_host')) {
			curl_setopt($curlCh, CURLOPT_PROXY, $httpProxyHost);
			curl_setopt($curlCh, CURLOPT_PROXYPORT, Config::getVar('proxy', 'http_port', '80'));

			if ($username = Config::getVar('proxy', 'username')) {
				curl_setopt($curlCh, CURLOPT_PROXYUSERPWD, $username . ':' . Config::getVar('proxy', 'password'));
			}
		}

        if ($isRedeposit) {
            $dataCiteAPIUrl = str_replace(array('api', '/dois'), array('mds', '/metadata/'), $dataCiteAPIUrl);
            $dataCiteAPIUrl .= $doi;
            curl_setopt($curlCh, CURLOPT_HTTPHEADER, array('Content-Type: text/plain;charset=UTF-8'));
        } else {
            curl_setopt($curlCh, CURLOPT_HTTPHEADER, array('Content-Type: application/vnd.api+json'));
        }

		curl_setopt($curlCh, CURLOPT_VERBOSE, TRUE);
		curl_setopt($curlCh, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curlCh, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($curlCh, CURLOPT_USERPWD, "$username:$password");
		curl_setopt($curlCh, CURLOPT_SSL_VERIFYPEER, TRUE);
		curl_setopt($curlCh, CURLOPT_URL, $dataCiteAPIUrl);

		assert( is_readable( $filename ) );
		$payload = file_get_contents( $filename );

		assert(empty($payload));
		$fp = fopen($filename, 'rb');

		curl_setopt($curlCh, CURLOPT_VERBOSE, FALSE);

		if ($isRedeposit) {
			curl_setopt($curlCh, CURLOPT_PUT, TRUE);
			curl_setopt($curlCh, CURLOPT_INFILE, $fp);
		} else {
            $payload = $this->createDatacitePayload($object, $url, $payload, TRUE);
			curl_setopt($curlCh, CURLOPT_POSTFIELDS, $payload);
		}

		$responseMessage = curl_exec($curlCh);
		$status = curl_getinfo($curlCh, CURLINFO_HTTP_CODE);
		curl_close($curlCh);
		fclose($fp);

		if (in_array($status, self::DATACITE_API_RESPONSE_OK, FALSE)) {
            // Test mode submits entirely different DOI and URL so the status of that should not be stored in the database
            // for the real DOI
            if (!$this->isTestMode($context)) {
                $this->updateDepositStatus($object, Doi::STATUS_REGISTERED);
            }
		} else if (self::DATACITE_API_RESPONSE_DOI_HAS_ALREADY_BEEN_TAKEN[0] === $status
			&& strpos($responseMessage, self::DATACITE_API_RESPONSE_DOI_HAS_ALREADY_BEEN_TAKEN[1]) > -1
		) {
			$this->depositXML($object, $context, $filename, TRUE);
		}

		return [
			self::RESPONSE_KEY_STATUS  => $status,
			self::RESPONSE_KEY_MESSAGE => $responseMessage
		];
	}

	public function createTestDOI($doi, Context $context) : array|string|null
    {
		return PKPString::regexp_replace('#^[^/]+/#', $this->getDataciteAPITestPrefix($context) . '/', $doi);
	}



	public function createDatacitePayload($obj, $url, $payload, $payLoadAvailable = FALSE) : bool|string
    {
		$doi = $obj->getDoi();
		$request = Application::get()->getRequest();
		$context = $request->getContext();
		if ($this->isTestMode($context)) {
			$doi = $this->createTestDOI($doi, $context);
		}
		if ($payLoadAvailable) {
			$jsonPayload = [
				'data' => [
					'id'         => $doi,
					'type'       => 'dois',
					'attributes' => [
						'event' => 'publish',
						'doi'   => $doi,
						'url'   => $url,
						'xml'   => base64_encode($payload)
					]
				]
			];
		} else {
			$jsonPayload = [
				'data' => [
					'type'       => 'dois',
					'attributes' => ['doi' => $doi]
				]
			];
		}

		try {
			return json_encode($jsonPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
		}
		catch (JsonException $e) {
			$notificationManager = new NotificationManager();
			$user = $request->getUser();
			if (NULL !== $user) {
				$notificationManager->createTrivialNotification(
					$user->getId(),
					PKPNotification::NOTIFICATION_TYPE_ERROR,
					['contents' => $e]
				);
			}
			return '';
		}
	}

	public function createNotifications(array $responses): array
    {
		$noError = true;
        $text = '';
        foreach ($responses as $id => $returnValues) {
			$status = $returnValues[self::RESPONSE_KEY_STATUS];
			$title = $returnValues[self::RESPONSE_KEY_TITLE];
			$message = $returnValues[self::RESPONSE_KEY_MESSAGE];
			$type = $returnValues[self::RESPONSE_KEY_TYPE];
			$action = $returnValues[self::RESPONSE_KEY_ACTION];
			$success = in_array($status, self::DATACITE_API_RESPONSE_OK, FALSE)
				|| (empty($status) && $message === self::RESPONSE_MESSAGE_MARKED_REGISTERED);


			if ($success) {
				switch ($action) {
					case self::RESPONSE_ACTION_DEPOSIT:
					case self::RESPONSE_ACTION_REDEPOSIT:
						$actionType = $action . 'ed';
						break;
					case self::RESPONSE_ACTION_MARKREGISTERED:
						$actionType = 'marked registered';
						break;
					default:
						$actionType = '';
				}
				$text .= 'Successfully ' . $actionType . '! ' . $type . '-id: ' . $id . '; Title: ' . $title
							. '; Status: ' . $status . '; Message: ' . $message . '<br/>';
			} else {
                $noError = false;
				self::writeLog(
					'STATUS ' . $status . ' | ' . strtoupper($type) . '-ID ' . $id . ' | ' . $message,
					strtoupper($action) . ' ERROR'
				);
			    $text .= 'Error! Action: ' . $action . '; ' . $type . '-id: ' . $id . '; Title: ' . $title
							. '; Status: ' . $status . '; Message: ' . $message . '<br/>';
			}
		}

        return [$noError, $text];
	}

	public static function writeLog($message, $level) : void {
		$time = new DateTime();
		$time = $time->format('d-M-Y H:i:s e');
		error_log("[$time] | $level | $message\n", 3, self::logFilePath());
	}

	public static function logFilePath() : string {

		return Config::getVar( 'files', 'files_dir' ) . '/DATACITE_ERROR.log';
	}

    public function exportAsDownload(Context $context, array $objects, ?bool $noValidation = null, ?array &$outputErrors = null): ?int
    {
        $fileManager = new TemporaryFileManager();

        // Export
        $result = $this->_checkForTar();
        if ($result === true) {
            $exportedFiles = [];
            foreach ($objects as $object) {
                // Get the XML
                $exportXml = $this->exportXML($object);
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
                    'export',
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
     * @param DataObject[] $objects
     *
     */
    public function exportAndDeposit(Context $context, array $objects, string &$responseMessage, ?bool $noValidation = null): bool
    {
        $fileManager = new FileManager();

        $result = [];
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
            $exportXml = $this->exportXML($object);

            // Write the XML to a file.
            // export file name example: datacite-20160723-160036-books-1-1.xml
            $objectFileNamePart = $this->_getObjectFileNamePart($object);
            $exportFileName = $this->getExportFileName($this->getExportPath(), $objectFileNamePart, $context, '.xml');
            $fileManager->writeFile($exportFileName, $exportXml);

            // Deposit the XML file.
            $response = $this->depositXML($object, $context, $exportFileName, $isRedeposit);

            if ($object instanceof Publication) {
                $response[self::RESPONSE_KEY_TITLE] = $object->getLocalizedTitle();
                $response[self::RESPONSE_KEY_TYPE] = self::RESPONSE_OBJECT_TYPE_SUBMISSION;
                $response[self::RESPONSE_KEY_ACTION] = $isRedeposit ? self::RESPONSE_ACTION_REDEPOSIT : self::RESPONSE_ACTION_DEPOSIT;
                $result[$object->getData('submissionId')] = $response;
            } elseif ( $object instanceof Chapter) {
                $publication = Repo::publication()->get($object->getData('publicationId'));
                $response[self::RESPONSE_KEY_TITLE] = $object->getTitle();
                $response[self::RESPONSE_KEY_TYPE] = self::RESPONSE_OBJECT_TYPE_CHAPTER;
                $response[self::RESPONSE_KEY_ACTION] = $isRedeposit ? self::RESPONSE_ACTION_REDEPOSIT : self::RESPONSE_ACTION_DEPOSIT;
                $result[$publication->getData('submissionId') . ' chapter ' . $object->getSourceChapterId()] = $response;
            } elseif ($object instanceof PublicationFormat) {
                $publication = Repo::publication()->get($object->getData('publicationId'));
                $response[self::RESPONSE_KEY_TITLE] = $publication->getLocalizedTitle();
                $response[self::RESPONSE_KEY_TYPE] = self::RESPONSE_OBJECT_TYPE_PUBLICATION_FORMAT;
                $response[self::RESPONSE_KEY_ACTION] = $isRedeposit ? self::RESPONSE_ACTION_REDEPOSIT : self::RESPONSE_ACTION_DEPOSIT;
                $result[$publication->getData('submissionId') . ' publication format ' . $object->getId()] = $response;
            }

            // Remove all temporary files.
            $fileManager->deleteByPath($exportFileName);
        }
        // Prepare response message and return status
        $notifications = $this->createNotifications( $result );
        $responseMessage = $notifications[1];
        return $notifications[0];
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
     * Get the XML for selected objects.
     *
     * @param mixed $object publication, chapter or publication format
     *
     * @return string XML document.
     */
    public function exportXML(DataObject $object): string
    {
        $request = Application::get()->getRequest();
        if ($object instanceof Publication) {
            $parent = null;
        } else  {
            $parent = Repo::publication()->get($object->getData('publicationId'));
        }
        $deployment = new DataciteExportDeployment($request, $this);
        $DOMDocument = new DOMDocument('1.0', 'utf-8');
        $DOMDocument->formatOutput = TRUE;
        $DOMDocument = $deployment->createNodes($DOMDocument, $object, $parent);
        return $DOMDocument->saveXML();
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

    public function markRegistered($context, $objects): void
    {
        foreach ($objects as $object) {
            if ($object instanceof Submission) {
                $doiIds = Repo::doi()->getDoisForSubmission($object->getId());
            }

            foreach ($doiIds as $doiId) {
                Repo::doi()->markRegistered($doiId);
            }
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


