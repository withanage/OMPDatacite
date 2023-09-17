<?php

/**
 * @file plugins/generic/datacite/classes/PubObjectsExportPlugin.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class PubObjectsExportPlugin
 *
 * @ingroup plugins
 *
 * @brief Basis class for XML metadata export plugins
 */

namespace APP\plugins\generic\datacite\classes;

use APP\core\Application;
use APP\facades\Repo;
use APP\monograph\ChapterDAO;
use APP\notification\NotificationManager;
use APP\publication\Publication;
use APP\publicationFormat\PublicationFormatDAO;
use APP\template\TemplateManager;
use PKP\context\Context;
use PKP\context\ContextDAO;
use PKP\core\DataObject;
use PKP\core\JSONMessage;
use PKP\core\PKPRequest;
use PKP\db\DAO;
use PKP\db\DAORegistry;
use PKP\db\SchemaDAO;
use PKP\file\FileManager;
use PKP\filter\FilterDAO;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\NullAction;
use PKP\notification\PKPNotification;
use PKP\plugins\Hook;
use PKP\plugins\importexport\PKPImportExportDeployment;
use PKP\plugins\ImportExportPlugin;
use PKP\plugins\PluginRegistry;
use PKP\submission\PKPSubmission;
use PKP\user\User;

abstract class PubObjectsExportPlugin extends ImportExportPlugin
{
    // The statuses.
    public const EXPORT_STATUS_ANY = '';
    public const EXPORT_STATUS_NOT_DEPOSITED = 'notDeposited';
    public const EXPORT_STATUS_MARKEDREGISTERED = 'markedRegistered';
    public const EXPORT_STATUS_REGISTERED = 'registered';

    // The actions.
    public const EXPORT_ACTION_EXPORT = 'export';
    public const EXPORT_ACTION_MARKREGISTERED = 'markRegistered';
    public const EXPORT_ACTION_DEPOSIT = 'deposit';

    // Configuration errors.
    public const EXPORT_CONFIG_ERROR_SETTINGS = 0x02;

    /** @var ?PubObjectCache */
    public ?PubObjectCache $_cache;

    /**
     * Get the plugin cache
     *
     * @return PubObjectCache
     */
    public function getCache(): PubObjectCache
    {
        if (!$this->_cache instanceof PubObjectCache) {
            // Instantiate the cache.
            $this->_cache = new PubObjectCache();
        }
        return $this->_cache;
    }

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null): bool
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        if (Application::isUnderMaintenance()) {
            return false;
        }

        $this->addLocaleData();

        Hook::add('AcronPlugin::parseCronTab', [$this, 'callbackParseCronTab']);
        foreach ($this->_getDAOs() as $dao) {
            if ($dao instanceof SchemaDAO) {
                Hook::add('Schema::get::' . $dao->schemaName, $this->addToSchema(...));
            } else {
                Hook::add(strtolower_codesafe(get_class($dao)) . '::getAdditionalFieldNames', $this->getAdditionalFieldNames(...));
            }
        }
        return true;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request): JSONMessage
    {
        $user = $request->getUser();
        $router = $request->getRouter();
        $context = $router->getContext($request);

        $form = $this->_instantiateSettingsForm($context);
        $notificationManager = new NotificationManager();
        switch ($request->getUserVar('verb')) {
            case 'save':
                $form->readInputData();
                if ($form->validate()) {
                    $form->execute();
                    $notificationManager->createTrivialNotification($user->getId(), PKPNotification::NOTIFICATION_TYPE_SUCCESS);
                    return new JSONMessage(true);
                } else {
                    return new JSONMessage(true, $form->fetch($request));
                }
                // no break
            case 'index':
                $form->initData();
                return new JSONMessage(true, $form->fetch($request));
            case 'statusMessage':
                $statusMessage = $this->getStatusMessage($request);
                if ($statusMessage) {
                    $templateMgr = TemplateManager::getManager($request);
                    $templateMgr->assign([
                        'statusMessage' => htmlentities($statusMessage),
                    ]);
                    return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('statusMessage.tpl')));
                }
        }
        return parent::manage($args, $request);
    }

    /**
     * @copydoc ImportExportPlugin::display()
     */
    public function display($args, $request): void
    {
        parent::display($args, $request);

        $context = $request->getContext();
        switch (array_shift($args)) {
            case 'index':
            case '':
                // Check for configuration errors:
                $configurationErrors = [];
                // missing plugin settings
                $form = $this->_instantiateSettingsForm($context);
                foreach ($form->getFormFields() as $fieldName => $fieldType) {
                    if ($form->isOptional($fieldName)) {
                        continue;
                    }
                    $pluginSetting = $this->getSetting($context->getId(), $fieldName);
                    if (empty($pluginSetting)) {
                        $configurationErrors[] = self::EXPORT_CONFIG_ERROR_SETTINGS;
                        break;
                    }
                }

                // Add link actions
                $actions = $this->getExportActions($context);
                $actionNames = array_intersect_key($this->getExportActionNames(), array_flip($actions));
                $linkActions = [];
                foreach ($actionNames as $action => $actionName) {
                    $linkActions[] = new LinkAction($action, new NullAction(), $actionName);
                }
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->assign([
                    'plugin' => $this,
                    'actionNames' => $actionNames,
                    'configurationErrors' => $configurationErrors,
                ]);
                break;
            case 'exportPublications':
            case 'exportChapters':
            case 'exportRepresentations':
                $this->prepareAndExportPubObjects($request, $context);
        }
    }

    /**
     * Gathers relevant pub objects and runs export action
     *
     * @param PKPRequest $request
     * @param Context $context
     * @param array $args Optional args for passing in submissionIds from external API calls
     */
    public function prepareAndExportPubObjects(PKPRequest $request, Context $context, array $args = []): void
    {
        $selectedPublications = (array) $request->getUserVar('selectedPublications');
        $selectedChapters = (array) $request->getUserVar('selectedChapters');
        $selectedRepresentations = (array) $request->getUserVar('selectedRepresentations');
        $tab = (string) $request->getUserVar('tab');
        $noValidation = !$request->getUserVar('validation');
        $objects = [];
        $filter = '';
        $objectsFileNamePart = '';

        if (!empty($args['publicationIds'])) {
            $selectedPublications = (array) $args['publicationIds'];
        }
        if (!empty($args['chapterIds'])) {
            $selectedChapters = (array) $args['chapterIds'];
        }
        if (empty($selectedPublications) && empty($selectedChapters) && empty($selectedRepresentations)) {
            fatalError(__('plugins.importexport.common.error.noObjectsSelected'));
        }
        if (!empty($selectedPublications)) {
            $objects = $this->getPublishedPublications($selectedPublications, $context);
            $filter = $this->getPublicationFilter();
            $objectsFileNamePart = 'books';
        } elseif (!empty($selectedChapters)) {
            $objects = $this->getPublishedChapters($selectedChapters, $context);
            $filter = $this->getChapterFilter();
            $objectsFileNamePart = 'chapters';
        } elseif (!empty($selectedRepresentations)) {
            $objects = $this->getPublishedPublicationFormats($selectedRepresentations, $context);
            $filter = $this->getRepresentationFilter();
            $objectsFileNamePart = 'publicationFormats';
        }

        // Execute export action
        $this->executeExportAction($request, $objects, $filter, $tab, $objectsFileNamePart, $noValidation);
    }

    /**
     * Execute export action.
     *
     * @param PKPRequest $request
     * @param array $objects Array of objects to be exported
     * @param string $filter Filter to use
     * @param string $tab Tab to return to
     * @param string $objectsFileNamePart Export file name part for this kind of objects
     * @param ?bool $noValidation If set to true no XML validation will be done
     * @param bool $shouldRedirect If set to true, will redirect to `$tab`. Should be true if executed within an ImportExportPlugin.
     */
    public function executeExportAction(PKPRequest $request, array $objects, string $filter, string $tab, string $objectsFileNamePart, ?bool $noValidation = null, bool $shouldRedirect = true): void
    {
        $context = $request->getContext();
        $path = ['plugin', $this->getName()];
        if ($this->_checkForExportAction(self::EXPORT_ACTION_EXPORT)) {
            assert($filter != null);

            $onlyValidateExport = $request->getUserVar('onlyValidateExport');
            if ($onlyValidateExport) {
                $noValidation = false;
            }

            // Get the XML
            $exportXml = $this->exportXML($objects, $filter, $context, $noValidation);

            if ($onlyValidateExport) {
                if ($exportXml !== true) {
                    $this->_sendNotification(
                        $request->getUser(),
                        'plugins.importexport.common.validation.success',
                        PKPNotification::NOTIFICATION_TYPE_SUCCESS
                    );
                } else {
                    $this->_sendNotification(
                        $request->getUser(),
                        'plugins.importexport.common.validation.fail',
                        PKPNotification::NOTIFICATION_TYPE_ERROR
                    );
                }

                if ($shouldRedirect) {
                    $request->redirect(null, null, null, $path, null, $tab);
                }
            } else {
                $fileManager = new FileManager();
                $exportFileName = $this->getExportFileName($this->getExportPath(), $objectsFileNamePart, $context, '.xml');
                $fileManager->writeFile($exportFileName, $exportXml);
                $fileManager->downloadByPath($exportFileName);
                $fileManager->deleteByPath($exportFileName);
            }
        } elseif ($this->_checkForExportAction(self::EXPORT_ACTION_DEPOSIT)) {
            assert($filter != null);
            // Get the XML
            $exportXml = $this->exportXML($objects, $filter, $context, $noValidation);
            // Write the XML to a file.
            // export file name example: crossref-20160723-160036-articles-1.xml
            $fileManager = new FileManager();
            $exportFileName = $this->getExportFileName($this->getExportPath(), $objectsFileNamePart, $context, '.xml');
            $fileManager->writeFile($exportFileName, $exportXml);
            // Deposit the XML file.
            $result = $this->depositXML($objects, $context, $exportFileName);
            // send notifications
            if ($result === true) {
                $this->_sendNotification(
                    $request->getUser(),
                    $this->getDepositSuccessNotificationMessageKey(),
                    PKPNotification::NOTIFICATION_TYPE_SUCCESS
                );
            } else {
                if (is_array($result)) {
                    foreach ($result as $error) {
                        assert(is_array($error) && count($error) >= 1);
                        $this->_sendNotification(
                            $request->getUser(),
                            $error[0],
                            PKPNotification::NOTIFICATION_TYPE_ERROR,
                            ($error[1] ?? null)
                        );
                    }
                }
            }
            // Remove all temporary files.
            $fileManager->deleteByPath($exportFileName);
            if ($shouldRedirect) {
                // redirect back to the right tab
                $request->redirect(null, null, null, $path, null, $tab);
            }
        } elseif ($this->_checkForExportAction(self::EXPORT_ACTION_MARKREGISTERED)) {
            $this->markRegistered($context, $objects);
            if ($shouldRedirect) {
                // redirect back to the right tab
                $request->redirect(null, null, null, $path, null, $tab);
            }
        } else {
            $dispatcher = $request->getDispatcher();
            $dispatcher->handle404();
        }
    }

    /**
     * Get the locale key used in the notification for
     * the successful deposit.
     */
    public function getDepositSuccessNotificationMessageKey(): string
    {
        return 'plugins.importexport.common.register.success';
    }

    /**
     * Deposit XML document.
     * This must be implemented in the subclasses, if the action is supported.
     *
     * @param mixed $objects Array of or single published publication, chapter or publication format
     * @param Context $context
     * @param string $filename Export XML filename
     *
     * @return bool|array Whether the XML document has been registered
     */
    abstract public function depositXML(array|DataObject $objects, Context $context, string $filename): bool|array;

    /**
     * Get detailed message of the object status i.e. failure messages.
     * Parameters needed have to be in the request object.
     *
     * @param PKPRequest $request
     *
     * @return ?string Preformatted text that will be displayed in a div element in the modal
     */
    public function getStatusMessage(PKPRequest $request): ?string
    {
        return null;
    }

    /**
     * Get the publication filter.
     *
     * @return string|null
     */
    public function getPublicationFilter(): ?string
    {
        return null;
    }

    /**
     * Get the chapter filter.
     *
     * @return string|null
     */
    public function getChapterFilter(): ?string
    {
        return null;
    }

    /**
     * Get the representation filter.
     *
     * @return string|null
     */
    public function getRepresentationFilter(): ?string
    {
        return null;
    }

    /**
     * Get status names for the filter search option.
     *
     * @return array (string status => string text)
     */
    public function getStatusNames(): array
    {
        return [
            self::EXPORT_STATUS_ANY => __('plugins.importexport.common.status.any'),
            self::EXPORT_STATUS_NOT_DEPOSITED => __('plugins.importexport.common.status.notDeposited'),
            self::EXPORT_STATUS_MARKEDREGISTERED => __('plugins.importexport.common.status.markedRegistered'),
            self::EXPORT_STATUS_REGISTERED => __('plugins.importexport.common.status.registered'),
        ];
    }

    /**
     * Get status actions for the display to the user,
     * i.e. links to a web site with more information about the status.
     *
     * @param object $pubObject
     *
     * @return array (string status => link)
     */
    public function getStatusActions(object $pubObject): array
    {
        return [];
    }

    /**
     * Get actions.
     *
     * @param Context $context
     *
     * @return array
     */
    public function getExportActions(Context $context): array
    {
        $actions = [self::EXPORT_ACTION_EXPORT, self::EXPORT_ACTION_MARKREGISTERED];
        if ($this->getSetting($context->getId(), 'username') && $this->getSetting($context->getId(), 'password')) {
            array_unshift($actions, self::EXPORT_ACTION_DEPOSIT);
        }
        return $actions;
    }

    /**
     * Get action names.
     *
     * @return array (string action => string text)
     */
    public function getExportActionNames(): array
    {
        return [
            self::EXPORT_ACTION_DEPOSIT => __('plugins.importexport.common.action.register'),
            self::EXPORT_ACTION_EXPORT => __('plugins.importexport.common.action.export'),
            self::EXPORT_ACTION_MARKREGISTERED => __('plugins.importexport.common.action.markRegistered'),
        ];
    }

    /**
     * Return the name of the plugin's deployment class.
     *
     * @return string
     */
    abstract public function getExportDeploymentClassName(): string;

    /**
     * Return the name of the plugin's settings form class.
     *
     * @return string
     */
    abstract public function getSettingsFormClassName(): string;

    /**
     * Get the XML for selected objects.
     *
     * @param mixed $objects Array of or single published submission, issue or galley
     * @param string $filter
     * @param Context $context
     * @param bool $noValidation If set to true no XML validation will be done
     * @param null|mixed $outputErrors
     *
     * @return string|bool XML document.
     */
    public function exportXML(array|DataObject$objects, string $filter, Context $context, ?bool $noValidation = null, mixed &$outputErrors = null): string|bool
    {
        $filterDao = DAORegistry::getDAO('FilterDAO'); /** @var FilterDAO $filterDao */
        $exportFilters = $filterDao->getObjectsByGroup($filter);
        assert(count($exportFilters) == 1); // Assert only a single serialization filter
        $exportFilter = array_shift($exportFilters);
        $exportDeployment = $this->_instantiateExportDeployment($context);
        $exportFilter->setDeployment($exportDeployment);
        if ($noValidation) {
            $exportFilter->setNoValidation($noValidation);
        }
        libxml_use_internal_errors(true);
        $exportXml = $exportFilter->execute($objects, true);
        $xml = $exportXml->saveXml();
        $errors = array_filter(libxml_get_errors(), function ($a) {
            return $a->level == LIBXML_ERR_ERROR || $a->level == LIBXML_ERR_FATAL;
        });
        if (!empty($errors)) {
            if ($outputErrors === null) {
                $this->displayXMLValidationErrors($errors, $xml);
            } else {
                $outputErrors = $errors;
            }
        }
        return $xml;
    }

    /**
     * Mark selected submissions or issues as registered.
     *
     * @param Context $context
     * @param array $objects Array of published publications, chapters or publication formats
     */
    public function markRegistered(Context $context, array $objects): void
    {
        foreach ($objects as $object) {
            $object->setData($this->getDepositStatusSettingName(), self::EXPORT_STATUS_MARKEDREGISTERED);
            $this->updateObject($object);
        }
    }

    /**
     * Update the given object.
     *
     * @param DataObject $object
     */
    protected function updateObject(DataObject $object): void
    {
        // Register a hook for the required additional
        // object fields. We do this on a temporary
        // basis as the hook adds a performance overhead
        // and the field will "stealthily" survive even
        // when the DAO does not know about it.
        $dao = $object->getDAO();
        $dao->update($object);
    }

    /**
     * Add properties for this type of public identifier to the entity's list for
     * storage in the database.
     * This is used for non-SchemaDAO-backed entities only.
     *
     * @see PubObjectsExportPlugin::addToSchema()
     *
     * @param string $hookName
     * @param DAO $dao
     * @param array $additionalFields
     *
     * @return false
     */
    public function getAdditionalFieldNames(string $hookName, DAO $dao, array &$additionalFields): bool
    {
        foreach ($this->_getObjectAdditionalSettings() as $fieldName) {
            $additionalFields[] = $fieldName;
        }
        return false;
    }

    /**
     * Add properties for this type of public identifier to the entity's list for
     * storage in the database.
     * This is used for SchemaDAO-backed entities only.
     *
     * @param string $hookName `Schema::get::publication`
     * @param array  $params
     *
     * @return bool
     * @see PKPPubIdPlugin::getAdditionalFieldNames()
     */
    public function addToSchema(string $hookName, array $params): bool
    {
        $schema = & $params[0];
        foreach ($this->_getObjectAdditionalSettings() as $fieldName) {
            $schema->properties->{$fieldName} = (object) [
                'type' => 'string',
                'apiSummary' => true,
                'validation' => ['nullable'],
            ];
        }

        return false;
    }

    /**
     * Get a list of additional setting names that should be stored with the objects.
     *
     * @return array
     */
    protected function _getObjectAdditionalSettings(): array
    {
        return [$this->getDepositStatusSettingName()];
    }

    /**
     * @copydoc AcronPlugin::parseCronTab()
     */
    public function callbackParseCronTab($hookName, $args): bool
    {
        $taskFilesPath = & $args[0];

        $scheduledTasksPath = "{$this->getPluginPath()}/scheduledTasks.xml";

        if (!file_exists($scheduledTasksPath)) {
            return false;
        }

        $taskFilesPath[] = $scheduledTasksPath;
        return false;
    }

    /**
     * Retrieve all unregistered Publications.
     *
     * @param Context $context
     *
     * @return array
     */
    public function getUnregisteredPublications(Context $context): array
    {
        // Retrieve all published publications that have not yet been registered.
        $publications = Repo::publication()
            ->getCollector()
            ->filterByContextIds([$context->getId()])
            ->getMany()
            ->toArray();

        $unregisteredPublications = [];
        /** @var Publication $publication */
        foreach ($publications as $publication) {
            if ($publication->getData($this->getDepositStatusSettingName()) === self::EXPORT_STATUS_NOT_DEPOSITED) {
                $unregisteredPublications[] = $publication;
            }
        }

        return $unregisteredPublications;
    }
    /**
     * Check whether we are in test mode.
     *
     * @param Context $context
     *
     * @return bool
     */
    public function isTestMode(Context $context): bool
    {
        return ($this->getSetting($context->getId(), 'testMode') == 1);
    }

    /**
     * Get deposit status setting name.
     *
     * @return string
     */
    public function getDepositStatusSettingName(): string
    {
        return $this->getPluginSettingsPrefix() . '::status';
    }



    /**
     * @copydoc PKPImportExportPlugin::usage
     */
    public function usage($scriptName): void
    {
        echo __(
            'plugins.importexport.' . $this->getPluginSettingsPrefix() . '.cliUsage',
            [
                'scriptName' => $scriptName,
                'pluginName' => $this->getName(),
            ]
        ) . "\n";
    }

    /**
     * @copydoc PKPImportExportPlugin::executeCLI()
     */
    public function executeCLI($scriptName, &$args): void
    {
        $command = array_shift($args);
        if (!in_array($command, ['export', 'register'])) {
            $this->usage($scriptName);
            return;
        }

        $outputFile = $command == 'export' ? array_shift($args) : null;
        $contextPath = array_shift($args);
        $objectType = array_shift($args);

        $contextDao = DAORegistry::getDAO('ContextDAO'); /** @var ContextDAO $contextDao */
        $context = $contextDao->getByPath($contextPath);
        if (!$context) {
            if ($contextPath != '') {
                echo __('plugins.importexport.common.cliError') . "\n";
                echo __('plugins.importexport.common.error.unknownContext', ['contextPath' => $contextPath]) . "\n\n";
            }
            $this->usage($scriptName);
            return;
        }

        PluginRegistry::loadCategory('pubIds', true, $context->getId());

        if ($outputFile) {
            if ($this->isRelativePath($outputFile)) {
                $outputFile = PWD . '/' . $outputFile;
            }
            $outputDir = dirname($outputFile);
            if (!is_writable($outputDir) || (file_exists($outputFile) && !is_writable($outputFile))) {
                echo __('plugins.importexport.common.cliError') . "\n";
                echo __('plugins.importexport.common.export.error.outputFileNotWritable', ['param' => $outputFile]) . "\n\n";
                $this->usage($scriptName);
                return;
            }
        }

        switch ($objectType) {
            case 'publications':
                $objects = $this->getPublishedPublications($args, $context);
                $filter = $this->getPublicationFilter();
                $objectsFileNamePart = 'publications';
                break;
            case 'chapters':
                $objects = $this->getPublishedChapters($args, $context);
                $filter = $this->getChapterFilter();
                $objectsFileNamePart = 'chapters';
                break;
            case 'publicationFormats':
                $objects = $this->getPublishedPublicationFormats($args, $context);
                $filter = $this->getRepresentationFilter();
                $objectsFileNamePart = 'publicationFormats';
                break;
            default:
                $this->usage($scriptName);
                return;
        }
        if (empty($objects)) {
            echo __('plugins.importexport.common.cliError') . "\n";
            echo __('plugins.importexport.common.error.unknownObjects') . "\n\n";
            $this->usage($scriptName);
            return;
        }
        if (!$filter) {
            $this->usage($scriptName);
            return;
        }

        $this->executeCLICommand($scriptName, $command, $context, $outputFile, $objects, $filter, $objectsFileNamePart);
    }

    /**
     * Execute the CLI command
     *
     * @param string $scriptName The name of the command-line script (displayed as usage info)
     * @param string $command (export or register)
     * @param Context $context
     * @param string $outputFile Path to the file where the exported XML should be saved
     * @param array $objects Objects to be exported or registered
     * @param string $filter Filter to use
     * @param string $objectsFileNamePart Export file name part for this kind of objects
     */
    public function executeCLICommand(string $scriptName, string $command, Context $context, string $outputFile, array $objects, string $filter, string $objectsFileNamePart): void
    {
        $exportXml = $this->exportXML($objects, $filter, $context);
        if ($command == 'export' && $outputFile) {
            file_put_contents($outputFile, $exportXml);
        }

        if ($command == 'register') {
            $fileManager = new FileManager();
            $exportFileName = $this->getExportFileName($this->getExportPath(), $objectsFileNamePart, $context, '.xml');
            $fileManager->writeFile($exportFileName, $exportXml);
            $result = $this->depositXML($objects, $context, $exportFileName);
            if ($result === true) {
                echo __('plugins.importexport.common.register.success') . "\n";
            } else {
                echo __('plugins.importexport.common.cliError') . "\n";
                if (is_array($result)) {
                    foreach ($result as $error) {
                        assert(is_array($error) && count($error) >= 1);
                        $errorMessage = __($error[0], ['param' => ($error[1] ?? null)]);
                        echo "*** {$errorMessage}\n";
                    }
                    echo "\n";
                } else {
                    echo __('plugins.importexport.common.register.error.mdsError', ['param' => ' - ']) . "\n\n";
                }
                $this->usage($scriptName);
            }
            $fileManager->deleteByPath($exportFileName);
        }
    }

    /**
     * Get published publications from publication IDs.
     *
     * @param array   $publicationIds
     * @param Context $context
     *
     * @return array
     */
    public function getPublishedPublications(array $publicationIds, Context $context): array
    {
        $allPublicationIds = Repo::publication()
            ->getCollector()
            ->filterByContextIds([$context->getId()])
            ->getIds()
            ->toArray();
        $validPublicationIds = array_intersect($allPublicationIds, $publicationIds);
        $validPublishedPublications = [];
        foreach ($validPublicationIds as $publicationId) {
            $publication = Repo::publication()->get($publicationId);
            if( $publication->getData('status') === PKPSubmission::STATUS_PUBLISHED ) {
                $validPublishedPublications[$publicationId] = $publication;
            }
        }
        return $validPublishedPublications;
    }

    /**
     * Get published chapters from chapter IDs.
     *
     * @param array $chapterIds
     * @param Context $context
     *
     * @return array
     */
    public function getPublishedChapters(array $chapterIds, Context $context): array
    {
        $chapters = [];
        /** @var ChapterDAO $chapterDao */
        $chapterDao = DAORegistry::getDAO('ChapterDAO');
        foreach ($chapterIds as $chapterId) {
            $chapter = $chapterDao->getChapter( $chapterId );
            $publication = Repo::publication()->get($chapter->getData('publicationId'));
            if( $publication->getData('status') === PKPSubmission::STATUS_PUBLISHED ) {
                $chapters[$chapterId] = $chapter;
            }
        }

        return $chapters;
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
        $publicationFormats = [];
        /** @var PublicationFormatDAO $publicationFormatDao */
        $publicationFormatDao = DAORegistry::getDAO('PublicationFormatDAO');
        foreach ($publicationFormatIds as $publicationFormatId) {
            $publicationFormat = $publicationFormatDao->getById($publicationFormatId);
            $publication = Repo::publication()->get($publicationFormat->getData('publicationId'));
            if( $publication->getData('status') === PKPSubmission::STATUS_PUBLISHED ) {
                $publicationFormats[$publicationFormatId] = $publicationFormat;
            }
        }

        return $publicationFormats;
    }

    /**
     * Add a notification.
     *
     * @param User        $user
     * @param string      $message          An i18n key.
     * @param int         $notificationType One of the NOTIFICATION_TYPE_* constants.
     * @param string|null $param            An additional parameter for the message.
     */
    public function _sendNotification(User $user, string $message, int $notificationType, ?string $param = null): void
    {
        static $notificationManager = null;
        $notificationManager ??= new NotificationManager();
        $params = is_null($param) ? [] : ['param' => $param];
        $notificationManager->createTrivialNotification(
            $user->getId(),
            $notificationType,
            ['contents' => __($message, $params)]
        );
    }

    /**
     * Instantiate the export deployment.
     *
     * @param Context $context
     *
     * @return PKPImportExportDeployment
     */
    public function _instantiateExportDeployment(Context $context): PKPImportExportDeployment
    {
        $exportDeploymentClassName = $this->getExportDeploymentClassName();
        return new $exportDeploymentClassName($context, $this);
    }

    /**
     * Instantiate the settings form.
     *
     * @param Context $context
     *
     * @return mixed
     */
    public function _instantiateSettingsForm(Context $context): mixed
    {
        $settingsFormClassName = $this->getSettingsFormClassName();
        return new $settingsFormClassName($this, $context->getId());
    }

    /**
     * Get the DAOs for objects that need to be augmented with additional settings.
     *
     * @return array
     */
    protected function _getDAOs(): array
    {
        return [
            Repo::publication()->dao,
            Application::getRepresentationDAO(),
            DAORegistry::getDAO('ChapterDAO'),
            Repo::submissionFile()->dao,
        ];
    }

    /**
     * Checks for export action type as set user var and as action passed from API call
     *
     * @param string $exportAction Action to check for
     *
     * @return bool
     */
    protected function _checkForExportAction(string $exportAction): bool
    {
        $request = $this->getRequest();
        if ($request->getUserVar($exportAction)) {
            return true;
        } elseif ($request->getUserVar('action') == $exportAction) {
            return true;
        }

        return false;
    }
}
