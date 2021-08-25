<?php
/** @noinspection PhpParamsInspection */
import('lib.pkp.classes.plugins.ImportExportPlugin');
import('lib.pkp.classes.file.FileManager');
import('lib.pkp.classes.plugins.PKPPubIdPluginDAO');
import('classes.notification.NotificationManager');
import('plugins.importexport.datacite.DataciteExportDeployment');


class DataciteExportPlugin extends ImportExportPlugin {
	#region constants

	//additional field names
	public const DEPOSIT_STATUS_FIELD_NAME = 'datacite-export::status';

	//pagination
	private const PAGINATION_DEFAULT_ITEMS_PER_PAGE = 10;

	//actions
	private const EXPORT_ACTION_MARKREGISTERED = 'markRegistered';
	private const EXPORT_ACTION_DEPOSIT        = 'deposit';

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
	private const RESPONSE_MESSAGE_NOT_POSSIBLE      = 'Not possible: already deposited';
	private const RESPONSE_OBJECT_TYPE_SUBMISSION    = 'Submission';
	private const RESPONSE_OBJECT_TYPE_CHAPTER       = 'Chapter';
	private const RESPONSE_ACTION_MARKREGISTERED     = 'mark registered';
	private const RESPONSE_ACTION_DEPOSIT            = 'deposit';
	private const RESPONSE_ACTION_REDEPOSIT          = 'redposit';

	//other
	private const DATACITE_API_RESPONSE_OK                         = array(200, 201);
	private const DATACITE_API_RESPONSE_DOI_HAS_ALREADY_BEEN_TAKEN = array(422, 'This DOI has already been taken');

	#endregion

	public function display($args, $request) : void {
		parent::display($args, $request);
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->assign('plugin', $this->getName());
		$params = $request->getUserVars();

		//CSRF Token Kontrolle
		if ((array_key_exists('isAjax', $params) || !empty($args[0])) && !$request->checkCSRF()) {
			$args[0] = '';
			$params['sel-search-type'] = 'title';
			$params['search-text'] = '';
			$params['sel-search-status'] = 'all';
			$user = $request->getUser();
			$userId = 'unknown';
			if (NULL !== $user) {
				$userId = $user->getId();
			}
			$responses = array();
			$responses[$userId] = array(
				self::RESPONSE_KEY_STATUS  => '403',
				self::RESPONSE_KEY_MESSAGE => 'CSRF Token is not valid.',
				self::RESPONSE_KEY_TITLE   => '',
				self::RESPONSE_KEY_ACTION  => 'CSRF',
				self::RESPONSE_KEY_TYPE    => 'user',
			);
			$this->createNotifications($responses);
		}

		//Ajax
		if (array_key_exists('isAjax', $params) && $params['isAjax'] === 'true') {
			$filteredData = $this->filterData(
				$params['sel-search-type'],
				$params['search-text'],
				$params['sel-search-status']
			);
			if (count($filteredData) > 0) {
				$this->buildSearchTable($filteredData);
			}
		} else {
			//reguläre Seitenaufrufe
			switch (array_shift($args)) {
				case 'settings':
					$this->getSettings($templateMgr);
					$this->updateSettings();
					$request->redirect(NULL, 'management', 'importexport', array('plugin', 'DataciteExportPlugin'));
				case '':
					$this->getSettings($templateMgr);
					$this->depositHandler($templateMgr);
					$templateMgr->display($this->getTemplateResource('index.tpl'));
					break;
				case 'export':
					$selectedSubmissions = $request->getUserVar('selectedSubmissions');
					$selectedChapters = $request->getUserVar('selectedChapters');

					if (NULL !== $selectedSubmissions && count($selectedSubmissions) !== 0) {
						if ($request->getUserVar(self::EXPORT_ACTION_DEPOSIT)) {
							$responses = $this->exportSubmissions($selectedSubmissions);
							$this->createNotifications($responses);
						} else {
							if ($request->getUserVar(self::EXPORT_ACTION_MARKREGISTERED)) {
								$responses = $this->markSubmissionsRegistered($selectedSubmissions);
								$this->createNotifications($responses);
							}
						}
					}

					if (NULL !== $selectedChapters && count($selectedChapters) !== 0) {
						if ($request->getUserVar(self::EXPORT_ACTION_DEPOSIT)) {
							$responses = $this->exportChapters($selectedChapters);
							$this->createNotifications($responses);
						} else {
							if ($request->getUserVar(self::EXPORT_ACTION_MARKREGISTERED)) {
								$responses = $this->markChaptersRegistered($selectedChapters);
								$this->createNotifications($responses);
							}
						}
					}
					$request->redirect(NULL, 'management', 'importexport', array('plugin', 'DataciteExportPlugin'));
					break;
				default:
					$dispatcher = $request->getDispatcher();
					if (NULL !== $dispatcher) {
						$dispatcher->handle404();
					}
			}
		}
	}

	public function getSettings(TemplateManager $templateMgr) : array {
		$request = $this->getRequest();
		$press = $request->getContext();
		$data = array();

		if (NULL !== $press) {
			$api = $this->getSetting($press->getId(), 'api');
			$templateMgr->assign('api', $api);
			$username = $this->getSetting($press->getId(), 'username');
			$templateMgr->assign('username', $username);
			$password = $this->getSetting($press->getId(), 'password');
			$templateMgr->assign('password', $password);
			$testMode = $this->getSetting($press->getId(), 'testMode');
			$templateMgr->assign('testMode', $testMode);
			$testPrefix = $this->getSetting($press->getId(), 'testPrefix');
			$templateMgr->assign('testPrefix', $testPrefix);
			$testRegistry = $this->getSetting($press->getId(), 'testRegistry');
			$templateMgr->assign('testRegistry', $testRegistry);
			$testUrl = $this->getSetting($press->getId(), 'testUrl');
			$templateMgr->assign('testUrl', $testUrl);
			$daraMode = $this->getSetting($press->getId(), 'daraMode');
			$templateMgr->assign('daraMode', $daraMode);

			$data = array($press, $api, $username, $password, $testMode, $testPrefix, $testRegistry, $testUrl, $daraMode);
		}

		return $data;
	}

	private function updateSettings() : void {
		$request = $this->getRequest();
		$context = $request->getContext();
		if (NULL !== $context) {
			$contextId = $context->getId();
			$userVars = $request->getUserVars();
			if (count($userVars) > 0) {
				$this->updateSetting($contextId, 'api', $userVars['api']);
				$this->updateSetting($contextId, 'daraMode', $userVars['daraMode']);
				$this->updateSetting($contextId, 'username', $userVars['username']);
				if (NULL !== $userVars['password'] && !empty($userVars['password'])) {
					$this->updateSetting($contextId, 'password', $userVars['password']);
				}
			}
		}
	}

	/**
	 * @return array
	 */
	private function load() : array {
		$itemsQueue = [];
		$request = $this->getRequest();
		$context = $request->getContext();
		if (NULL !== $context) {
			$submissionDAO = new SubmissionDAO();
			$submissions = $submissionDAO->getByContextId($context->getId());
			$submissions = $submissions->toAssociativeArray();
			krsort($submissions);

			$locale = AppLocale::getLocale();
			$contextPaths = $request->getRequestedContextPath();
			$workflowPath = $request->getBaseUrl()
				. '/index.php/'
				. $contextPaths[0]
				. '/workflow/access/';
			/** @var Submission $submission */
			foreach ($submissions as $submission) {
				$submissionId = $submission->getId();
				$publication = $submission->getCurrentPublication();

				if (NULL !== $publication) {
					$doi = $publication->getData('pub-id::doi');

					if ($submission->getData('status') === STATUS_PUBLISHED) {
						$publisherID = $publication->getData('pub-id::publisher-id');
						$published = '-';
						$currentStatus = $submission->getData(self::DEPOSIT_STATUS_FIELD_NAME);
						//No current status, then create status by publisherID
						if (NULL === $currentStatus || empty($currentStatus)) {
							$submission = $this->setInitialExportStatus($submission, $publisherID);
						}
						$isChapterPublicationDatesEnabled = FALSE;
						if (NULL !== $publisherID && !empty($publisherID)) {
							$published = (string) $publication->getData('datePublished');
							//removing time
							$published = explode(' ', $published);
							$published = $published[0];
							$isChapterPublicationDatesEnabled = (bool) $submission->getEnableChapterPublicationDates();
						}

						$chapters = $publication->getData('chapters');
						$chaptersData = array();

						/** @var Chapter $chapter */
						foreach ($chapters as $chapter) {
							$chapterPubId = $chapter->getData('pub-id::publisher-id');
							$currentChapterStatus = $chapter->getData(self::DEPOSIT_STATUS_FIELD_NAME);
							if (NULL === $currentChapterStatus || empty($currentChapterStatus)) {
								$chapter = $this->setInitialExportStatus($chapter, $chapterPubId);
							}

							if ($isChapterPublicationDatesEnabled) {
								$chapterPubDate = ($chapter->getDatePublished()) ?: '-';
							} else {
								$chapterPubDate = $published;
							}

							$chaptersData[] = array(
								'chapterId'      => $chapter->getId(),
								'chapterAuthors' => $chapter->getAuthorNamesAsString(),
								'chapterTitle'   => $chapter->getLocalizedTitle(),
								'chapterPubId'   => ($chapterPubId) ?: '',
								'chapterDoi'     => $chapter->getData('pub-id::doi'),
								'chapterPubDate' => $chapterPubDate,
								'chapterStatus'  => $chapter->getData(self::DEPOSIT_STATUS_FIELD_NAME),
								'chapterObject'  => $chapter,
							);
						}

						$itemsQueue[] = array(
							'id'               => $submissionId,
							'title'            => $publication->getLocalizedTitle($locale),
							'authors'          => $publication->getAuthorString($locale),
							'pubId'            => ($publisherID) ?: '',
							'chapters'         => $chaptersData,
							'doi'              => $doi,
							'date'             => $published,
							'workflow'         => $workflowPath . $submissionId,
							'status'           => $submission->getData(self::DEPOSIT_STATUS_FIELD_NAME),
							'submissionObject' => $submission,
						);

					}
				}
			}
		}

		return $itemsQueue;
	}

	/**
	 * @param string $searchType
	 * @param string $searchText
	 * @param string $searchStatus
	 *
	 * @return array
	 */
	private function filterData(string $searchType, string $searchText, string $searchStatus) : array {
		$useStatusFilter = ($searchStatus !== 'all');
		$useTextSearch = (!empty($searchText));
		$itemsQueue = $this->load();

		if ($useStatusFilter || $useTextSearch) {
			$filteredByStatus = array();
			$filteredByText = array();
			if ($useStatusFilter) {
				foreach ($itemsQueue as $item) {
					if ($item['status'] === $searchStatus) {
						$filteredByStatus[] = $item;
					} else {
						if (is_array($item['chapters'])) {
							foreach ($item['chapters'] as $chapter) {
								if (!empty($chapter['chapterDoi']) && $chapter['chapterStatus'] === $searchStatus) {
									$filteredByStatus[] = $item;
									break;
								}
							}
						}
					}
				}
			} else {
				$filteredByStatus = $itemsQueue;
			}

			if ($useTextSearch) {
				$searchTextArray = explode(' ', mb_strtolower($searchText));

				if ($searchType === 'authors') {
					foreach ($filteredByStatus as $item) {
						$authors = mb_strtolower($item['authors']);
						$found = TRUE;
						foreach ($searchTextArray as $text) {
							if (strpos($authors, $text) === FALSE) {
								$found = FALSE;
								break;
							}
						}
						if ($found) {
							$filteredByText[] = $item;
						}

					}
				} else {
					if ($searchType === 'title') {
						foreach ($filteredByStatus as $item) {
							$titles = array();
							/** @var Submission $submission */
							$submission = $item['submissionObject'];
							$publication = $submission->getCurrentPublication();
							$supportedLocales = AppLocale::getAllLocales();
							$found = FALSE;
							if (NULL !== $publication) {
								//first, only submission title
								foreach ($supportedLocales as $locale => $name) {
									$title = $publication->getLocalizedFullTitle($locale);
									if (NULL !== $title && !empty($title)) {
										$titles[] = mb_strtolower($title);
									}
								}
								foreach ($titles as $title) {
									$found = TRUE;
									foreach ($searchTextArray as $text) {
										if (strpos($title, $text) === FALSE) {
											$found = FALSE;
											break;
										}
									}
									if ($found) {
										break;
									}
								}
							}

							//no hit, then search in chapter title
							if (!$found) {
								$chapters = $item['chapters'];
								$chapterTitles = array();
								foreach ($chapters as $chapter) {
									/** @var Chapter $chapter */
									$chapter = $chapter['chapterObject'];
									foreach ($supportedLocales as $locale => $name) {
										$chapterTitle = $chapter->getTitle($locale);
										if (NULL !== $chapterTitle && !empty($chapterTitle)) {
											$chapterTitles[] = mb_strtolower($chapterTitle);
										}
									}
								}

								foreach ($chapterTitles as $title) {
									$found = TRUE;
									foreach ($searchTextArray as $text) {
										if (strpos($title, $text) === FALSE) {
											$found = FALSE;
											break;
										}
									}
									if ($found) {
										break;
									}
								}
							}

							if ($found) {
								$filteredByText[] = $item;
							}
						}
					}
				}
			} else {
				$filteredByText = $filteredByStatus;
			}

			return $filteredByText;
		}
		return $itemsQueue;
	}

	/**
	 * @param array $itemsQueue
	 */
	private function buildSearchTable(array $itemsQueue) : void {
		$html = '';
		foreach ($itemsQueue as $key => $item) {
			//submission row
			$html .= '<tr id="datacitelistgrid-row-' . $key . '" class="gridRow has_extras datacite-row';
			if ($key < self::PAGINATION_DEFAULT_ITEMS_PER_PAGE - 1) {
				$html .= ' datacite-show-row">';
			} else {
				$html .= ' datacite-hide-row">';
			}

			$arrowClass = (count($item['chapters']) < 1) ? ' datacite-hidden' : '';
			$html .= '<td class="first_column">'
				. '<a href="#" class="show_extras dropdown-' . $item['id'] . $arrowClass . '"></a>'
				. '<label for="select-' . $item['id'] . '"></label>'
				. '<input type="checkbox" id="select-' . $item['id']
				. '" name="selectedSubmissions[]" style="height: 15px; width: 15px;" value="' . $item['id']
				. '" class="submissionCheckbox"';
			if (empty($item['doi']) || $item['status'] === 'markedRegistered') {
				$html .= ' disabled';
			}
			$html .= '></td>'
				. '<td class=" pkp_helpers_text_left">'
				. '<span id="cell-' . $item['id'] . '-id" class="gridCellContainer">'
				. '<span class="label before_actions">' . $item['id'] . '</span></span></td>'
				. '<td class=" pkp_helpers_text_left">'
				. '<a href="' . $item['workflow'] . '" target="_self" class="pkpListPanelItem--submission__link">'
				. '<div id="cell-' . $item['id'] . '-authors" class="gridCellContainer datacite-ellipsis datacite-authors">'
				. $item['authors'] . ' </div>'
				. '<div id="cell-' . $item['id'] . '-title" class="gridCellContainer datacite-ellipsis">'
				. $item['title'] . '</div></a></td>'
				. '<td class="pkp_helpers_text_left">'
				. '<span id="cell-' . $item['id'] . '-published" class="gridCellContainer">' . $item['date'] . '</span></td>'
				. '<td class=" pkp_helpers_text_left">'
				. '<span id="cell-' . $item['id'] . '-pubId" class="gridCellContainer">'
				. '<span class="label datacite-break-word">';
			$html .= (empty($item['pubId'])) ? $item['doi'] : $item['pubId'];
			$html .= '</span ></span ></td >'
				. '<td class=" pkp_helpers_text_left">'
				. '<span id="cell-' . $item['id'] . '-status" class="gridCellContainer">'
				. '<span class="label datacite-break-word">';

			if ($item['status'] === 'notDeposited') {
				$html .= __('plugins.importexport.datacite.status.todeposit');
			} else {
				if ($item['status'] === 'registered') {
					$html .= __('plugins.importexport.datacite.status.registered');
				} else {
					if ($item['status'] === 'markedRegistered') {
						$html .= __('plugins.importexport.datacite.status.markedregistered');
					}
				}
			}
			$html .= '</span></span></td></tr>';

			//chapter row
			$html .= '<tr id="datacitelistgrid-row-' . $item['id'] . '-control-row" class="row_controls"><td colspan="6">'
				. '<table id="chapters-table-' . $item['id'] . '" class="datacite-table"><colgroup>'
				. '<col class="grid-column column-select" style="width: 5%;"><col class="grid-column column-id" style="width: 7.5%;">'
				. '<col class="grid-column column-title" style="width: 40%;"><col class="grid-column column-issue" style="width: 10%;">'
				. '<col class="grid-column column-pubId" style="width: 20%;"><col class="grid-column column-status" style="width: 17.5%;">'
				. '</colgroup><tbody>';

			foreach ($item['chapters'] as $chapterKey => $chapter) {
				$html .= '<tr id="chapter-row-' . $item['id'] . '-c' . $chapter['chapterId'] . '" class="gridRow">'
					. '<td class="first_column"><label for="select-' . $item['id'] . '-c' . $chapter['chapterId'] . '"></label>'
					. '<input type="checkbox" id="select-' . $item['id'] . '-c' . $chapter['chapterId']
					. '" name="selectedChapters[]" style="height: 15px; width: 15px;" value="' . $item['id'] . '-' . $chapter['chapterId'] . '"';
				if (empty($chapter['chapterDoi']) || $chapter['chapterStatus'] === 'markedRegistered') {
					$html .= ' disabled></td>';
				} else {
					$html .= ' class="select-chapter-' . $item['id'] . '"></td>';
				}
				$html .= '<td class=" pkp_helpers_text_left">'
					. '<span id="cell-' . $item['id'] . '-c' . $chapter['chapterId'] . '-id" class="gridCellContainer">'
					. '<span class="label before_actions">c' . $chapter['chapterId'] . '</span></span></td>'
					. '<td class=" pkp_helpers_text_left">'
					. '<div id="cell-' . $item['id'] . '-c' . $chapter['chapterId'] . '-authors" '
					. 'class="gridCellContainer datacite-ellipsis datacite-authors">' . $chapter['chapterAuthors'] . '</div>'
					. '<div id="cell-' . $item['id'] . '-c' . $chapter['chapterId'] . '-title" '
					. 'class="gridCellContainer datacite-ellipsis">' . $chapter['chapterTitle'] . '</div></td>'
					. '<td class=" pkp_helpers_text_left">'
					. '<span id="cell-' . $item['id'] . '-c' . $chapter['chapterId'] . '-published" class="gridCellContainer">'
					. $chapter['chapterPubDate'] . '</span></td>'
					. '<td class=" pkp_helpers_text_left">'
					. '<span id="cell-' . $item['id'] . '-c' . $chapter['chapterId'] . '-pubId" class="gridCellContainer">'
					. '<span class="label datacite-break-word">';
				$html .= (empty($chapter['chapterPubId'])) ? $chapter['chapterDoi'] : $chapter['chapterPubId'];
				$html .= '</span></span></td>'
					. '<td class=" pkp_helpers_text_left">'
					. '<span id="cell-' . $item['id'] . '-c' . $chapter['chapterId'] . '-status" class="gridCellContainer">'
					. '<span class="label datacite-break-word">';
				if ($chapter['chapterStatus'] === 'notDeposited') {
					$html .= __('plugins.importexport.datacite.status.todeposit');
				} else if ($chapter['chapterStatus'] === 'registered') {
					$html .= __('plugins.importexport.datacite.status.registered');
				} else if ($chapter['chapterStatus'] === 'markedRegistered') {
					$html .= __('plugins.importexport.datacite.status.markedregistered');
				}
				$html .= '</span></span></td></tr>';
			}
			$html .= '</tbody></table></td></tr>';
		}

		echo $html;
		die();
	}

	/**
	 * @param TemplateManager $templateMgr
	 */
	private function depositHandler(TemplateManager $templateMgr) : void {
		$request = $this->getRequest();
		$itemsQueue = $this->load();

		$templateMgr->assign('itemsQueue', $itemsQueue)
			->assign('itemsSizeQueue', count($itemsQueue))
			->assign('currentPage', 1)
			->assign('startItem', 1)
			->assign('endItem', self::PAGINATION_DEFAULT_ITEMS_PER_PAGE - 1)
			->assign('csrfToken', $request->getSession()
				->getCSRFToken()
			);
	}

	/**
	 * @param      $object
	 * @param      $publisherId
	 *
	 * @return mixed
	 */
	private function setInitialExportStatus($object, $publisherId) : object {
		$status = (NULL === $publisherId || empty($publisherId))
			? self::EXPORT_STATUS_NOT_DEPOSITED
			: self::EXPORT_STATUS_REGISTERED;

		$object->setData(self::DEPOSIT_STATUS_FIELD_NAME, $status);
		$this->updateObject($object);

		return $object;
	}

	private function markSubmissionsRegistered($submissionIds) : array {
		$submissionDao = new SubmissionDAO();
		$locale = AppLocale::getLocale();
		$request = $this->getRequest();
		$context = $request->getContext();
		$response = array();

		if (NULL !== $context) {
			foreach ($submissionIds as $submissionId) {
				/** @var Submission $submission */
				$submission = $submissionDao->getById($submissionId);
				if (NULL !== $submission) {
					$publication = $submission->getCurrentPublication();
					if (NULL !== $publication) {
						$currentStatus = $submission->getData(self::DEPOSIT_STATUS_FIELD_NAME);
						$message = self::RESPONSE_MESSAGE_NOT_POSSIBLE;
						if ($currentStatus !== self::EXPORT_STATUS_REGISTERED) {
							$submission->setData(self::DEPOSIT_STATUS_FIELD_NAME, self::EXPORT_STATUS_MARKEDREGISTERED);
							$this->updateObject($submission);
							$message = self::RESPONSE_MESSAGE_MARKED_REGISTERED;
						}
						$response[$submissionId] = array(
							self::RESPONSE_KEY_STATUS  => '',
							self::RESPONSE_KEY_MESSAGE => $message,
							self::RESPONSE_KEY_TITLE   => $publication->getLocalizedTitle($locale),
							self::RESPONSE_KEY_ACTION  => self::RESPONSE_ACTION_MARKREGISTERED,
							self::RESPONSE_KEY_TYPE    => self::RESPONSE_OBJECT_TYPE_SUBMISSION,
						);
					}
				}
			}
		}
		return $response;
	}

	private function markChaptersRegistered($chapterIds) : array {
		$chapterDao = new ChapterDAO();
		$submissionDao = new SubmissionDAO();
		$locale = AppLocale::getLocale();
		$response = array();

		foreach ($chapterIds as $submissionChapterId) {
			$submissionChapterId = explode('-', $submissionChapterId);
			$submissionId = (int) $submissionChapterId[0];
			$chapterId = (int) $submissionChapterId[1];
			/** @var Submission $submission */
			$submission = $submissionDao->getById($submissionId);

			if (NULL !== $submission) {
				$publication = $submission->getCurrentPublication();
				if (NULL !== $publication) {
					/** @var Chapter $chapter */
					$chapter = $chapterDao->getChapter($chapterId, $publication->getId());
					if (NULL !== $chapter) {
						$currentStatus = $chapter->getData(self::DEPOSIT_STATUS_FIELD_NAME);
						$message = self::RESPONSE_MESSAGE_NOT_POSSIBLE;
						if ($currentStatus !== self::EXPORT_STATUS_REGISTERED) {
							$chapter->setData(self::DEPOSIT_STATUS_FIELD_NAME, self::EXPORT_STATUS_MARKEDREGISTERED);
							$this->updateObject($chapter);
							$message = self::RESPONSE_MESSAGE_MARKED_REGISTERED;

						}
						$response[$submissionId . ' .c' . $chapterId] = array(
							self::RESPONSE_KEY_STATUS  => '',
							self::RESPONSE_KEY_MESSAGE => $message,
							self::RESPONSE_KEY_TITLE   => $chapter->getTitle($locale),
							self::RESPONSE_KEY_ACTION  => self::RESPONSE_ACTION_MARKREGISTERED,
							self::RESPONSE_KEY_TYPE    => self::RESPONSE_OBJECT_TYPE_CHAPTER,
						);
					}
				}
			}
		}
		return $response;
	}

	public function getRegistry($press) {
		$registry = $this->getSetting($press->getId(), 'api');
		if ($this->isTestMode($press)) {
			$registry = $this->getSetting($press->getId(), 'testRegistry');
		}
		return $registry;
	}

	public function isTestMode($press) : bool {

		$testMode = $this->getSetting( $press->getId(), 'testMode' );

		return ($testMode === 'on');
	}

	private function exportSubmissions($submissionIds) : array {
		$submissionDao = new SubmissionDAO();
		$locale = AppLocale::getLocale();
		$request = $this->getRequest();
		$press = $request->getContext();
		$fileManager = new FileManager();
		$result = array();
		$context = $request->getContext();

		if (NULL !== $context) {
			foreach ($submissionIds as $submissionId) {
				/** @var Submission $submission */
				$submission = $submissionDao->getById($submissionId);

				if (NULL !== $submission) {
					$publication = $submission->getCurrentPublication();
					if (NULL !== $publication) {
						$status = $submission->getData(self::DEPOSIT_STATUS_FIELD_NAME);
						if (NULL !== $status
							&& $status !== self::EXPORT_STATUS_MARKEDREGISTERED
							&& $publication->getData('pub-id::doi')) {
							$isRedeposit = ($status === self::EXPORT_STATUS_REGISTERED);
							$deployment = new DataciteExportDeployment($request, $this);
							$DOMDocument = new DOMDocument('1.0', 'utf-8');
							$DOMDocument->formatOutput = TRUE;
							$DOMDocument = $deployment->createNodes($DOMDocument, $publication, NULL, TRUE);
							$exportFileName =
								$this->getExportFileName(
									$this->getExportPath(),
									'datacite-' . $submissionId,
									$press
								);
							$exportXml = $DOMDocument->saveXML();
							$fileManager->writeFile($exportFileName, $exportXml);
							$response = $this->depositXML(
								$submission,
								$exportFileName,
								TRUE,
								$isRedeposit,
								$submissionId
							);
							$response[self::RESPONSE_KEY_TITLE] = $publication->getLocalizedTitle($locale);
							$response[self::RESPONSE_KEY_ACTION] = $isRedeposit
								? self::RESPONSE_ACTION_REDEPOSIT
								: self::RESPONSE_ACTION_DEPOSIT;
							$response[self::RESPONSE_KEY_TYPE] = self::RESPONSE_OBJECT_TYPE_SUBMISSION;
							$result[$submissionId] = $response;

							$fileManager->deleteByPath($exportFileName);
						}
					}
				}
			}
		}
		return $result;
	}

	private function exportChapters($chapterIds) : array {
		$submissionDao = new SubmissionDAO();
		$locale = AppLocale::getLocale();
		$request = $this->getRequest();
		$press = $request->getContext();
		$fileManager = new FileManager();
		$result = array();

		$chapterDao = new ChapterDAO();
		$context = $request->getContext();

		if (NULL !== $context) {
			foreach ($chapterIds as $submissionChapterId) {
				$submissionChapterId = explode('-', $submissionChapterId);
				$submissionId = (int) $submissionChapterId[0];
				$chapterId = (int) $submissionChapterId[1];
				$submission = $submissionDao->getById($submissionId);
				if (NULL !== $submission) {
					/** @var Submission $submission */
					$publication = $submission->getCurrentPublication();
					if (NULL !== $publication) {
						$publicationId = $publication->getId();
						/** @var Chapter $chapter */
						$chapter = $chapterDao->getChapter($chapterId, $publicationId);
						$status = $chapter->getData(self::DEPOSIT_STATUS_FIELD_NAME);

						if (NULL !== $status
							&& $status !== self::EXPORT_STATUS_MARKEDREGISTERED
							&& $chapter->getData('pub-id::doi')) {
							$isRedeposit = ($status === self::EXPORT_STATUS_REGISTERED);
							$deployment = new DataciteExportDeployment($request, $this);
							$DOMDocumentChapter = new DOMDocument('1.0', 'utf-8');
							$DOMDocumentChapter->formatOutput = TRUE;
							$DOMDocumentChapter = $deployment->createNodes($DOMDocumentChapter, $chapter, $submission, FALSE);
							$exportFileName = $this->getExportFileName(
								$this->getExportPath(),
								'datacite-' . $submissionId . 'c' . $chapter->getId(),
								$press
							);
							$exportXml = $DOMDocumentChapter->saveXML();
							$fileManager->writeFile($exportFileName, $exportXml);
							$response = $this->depositXML(
								$chapter,
								$exportFileName,
								FALSE,
								$isRedeposit,
								$submissionId
							);
							$response[self::RESPONSE_KEY_TITLE] = $chapter->getTitle($locale);
							$response[self::RESPONSE_KEY_ACTION] = $isRedeposit
								? self::RESPONSE_ACTION_REDEPOSIT
								: self::RESPONSE_ACTION_DEPOSIT;
							$response[self::RESPONSE_KEY_TYPE] = self::RESPONSE_OBJECT_TYPE_CHAPTER;

							$result[$submissionId . ' .c' . $chapter->getId()] = $response;
							$fileManager->deleteByPath($exportFileName);
						}

					}
				}
			}
		}


		return $result;
	}

	/**
	 * @param      $object
	 * @param      $filename
	 * @param bool $isSubmission
	 * @param      $isRedeposit
	 * @param int  $submissionId
	 *
	 * @return mixed
	 */
	public function depositXML($object, $filename, bool $isSubmission, $isRedeposit, $submissionId = 0) : array {
		$request = $this->getRequest();
		$press = $request->getContext();

		if ($isSubmission) {
			$publication = $object->getCurrentPublication();
			$doi = $publication->getData('pub-id::doi');
			$url = $request->url(
				$press->getPath(),
				'catalog',
				'book',
				array($submissionId)
			);
		} else {
			$doi = $object->getData('pub-id::doi');
			$url = $request->url(
				$press->getPath(),
				'catalog',
				'book',
				array($submissionId, 'c' . $object->getId())
			);
		}


		assert(!empty($doi));
		if ($this->isTestMode($press)) {
			$doi = $this->createTestDOI($doi);
		}

		assert(!empty($url));
		$curlCh = curl_init();

		$username = $this->getSetting($press->getId(), 'username');
		$api = $this->getSetting($press->getId(), 'api');
		$password = $this->getSetting($press->getId(), 'password');

		if ($httpProxyHost = Config::getVar('proxy', 'http_host')) {
			curl_setopt($curlCh, CURLOPT_PROXY, $httpProxyHost);
			curl_setopt($curlCh, CURLOPT_PROXYPORT, Config::getVar('proxy', 'http_port', '80'));

			if ($username = Config::getVar('proxy', 'username')) {
				curl_setopt($curlCh, CURLOPT_PROXYUSERPWD, $username . ':' . Config::getVar('proxy', 'password'));
			}
		}

		if ($this->isDara()) {
			curl_setopt($curlCh, CURLOPT_HTTPHEADER, array('Accept: application/json'));
			curl_setopt($curlCh, CURLOPT_HTTPHEADER, array('Content-Type: application/xml;charset=UTF-8'));
		} else {
			if ($isRedeposit) {
				$api = str_replace(array('api', '/dois'), array('mds', '/metadata/'), $api);
				$api .= $doi;
				curl_setopt($curlCh, CURLOPT_HTTPHEADER, array('Content-Type: text/plain;charset=UTF-8'));
			} else {
				curl_setopt($curlCh, CURLOPT_HTTPHEADER, array('Content-Type: application/vnd.api+json'));
			}
		}

		curl_setopt($curlCh, CURLOPT_VERBOSE, TRUE);
		curl_setopt($curlCh, CURLOPT_RETURNTRANSFER, TRUE);
		curl_setopt($curlCh, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
		curl_setopt($curlCh, CURLOPT_USERPWD, "$username:$password");
		curl_setopt($curlCh, CURLOPT_SSL_VERIFYPEER, TRUE);
		curl_setopt($curlCh, CURLOPT_URL, $api);

		assert( is_readable( $filename ) );
		$payload = file_get_contents( $filename );

		assert($payload !== FALSE && !empty($payload));
		$fp = fopen($filename, 'rb');

		curl_setopt($curlCh, CURLOPT_VERBOSE, FALSE);

		if ($isRedeposit) {
			curl_setopt($curlCh, CURLOPT_PUT, TRUE);
			curl_setopt($curlCh, CURLOPT_INFILE, $fp);
		} else {
			if (!$this->isDara()) {
				$payload = $this->createDatacitePayload($object, $url, $payload, TRUE);
			}
			curl_setopt($curlCh, CURLOPT_POSTFIELDS, $payload);
		}

		$responseMessage = curl_exec($curlCh);
		$status = curl_getinfo($curlCh, CURLINFO_HTTP_CODE);
		curl_close($curlCh);
		fclose($fp);

		if (in_array($status, self::DATACITE_API_RESPONSE_OK, FALSE)) {
			$this->setDOI($object, $isSubmission, $press, $doi);
		} else if (self::DATACITE_API_RESPONSE_DOI_HAS_ALREADY_BEEN_TAKEN[0] === $status
			&& strpos($responseMessage, self::DATACITE_API_RESPONSE_DOI_HAS_ALREADY_BEEN_TAKEN[1]) > -1
		) {
			$this->setDOI($object, $isSubmission, $press, $doi);
			//Redeposite
			$submissionDao = new SubmissionDAO();
			/** @var Submission $submission */
			$submission = $submissionDao->getById($submissionId);
			if ($isSubmission) {
				$submissionDao = new SubmissionDAO();
				/** @var Submission $submission */
				$submission = $submissionDao->getById($submissionId);
				$object = $submission;
			} else {
				$publication = $submission->getCurrentPublication();
				if (NULL !== $publication) {
					$chapterDao = new ChapterDAO();
					$publicationId = $publication->getId();
					/** @var Chapter $object */
					$chapter = $chapterDao->getChapter($object->getId(), $publicationId);
					$object = $chapter;
				}
			}

			$this->depositXML($object, $filename, $isSubmission, TRUE, $submissionId);
		}

		return array(
			self::RESPONSE_KEY_STATUS  => $status,
			self::RESPONSE_KEY_MESSAGE => $responseMessage
		);
	}

	public function createTestDOI($doi) {
		return PKPString::regexp_replace('#^[^/]+/#', $this->getDataciteAPITestPrefix() . '/', $doi);
	}

	public function getDataciteAPITestPrefix() {
		$request = $this->getRequest();
		$press = $request->getContext();

		return $this->getSetting( $press->getId(), 'testPrefix' );
	}

	public function isDara() : bool {
		$request = $this->getRequest();
		$press = $request->getContext();
		$daraMode = $this->getSetting($press->getId(), 'daraMode');

		return ($daraMode === 'on');
	}

	public function createDatacitePayload($obj, $url, $payload, $payLoadAvailable = FALSE) {
		$doi = $obj->getStoredPubId('doi');
		$request = $this->getRequest();
		$press = $request->getContext();
		if ($this->isTestMode($press)) {
			$doi = $this->createTestDOI($doi);
		}
		if ($payLoadAvailable) {
			$jsonPayload = array(
				'data' => array(
					'id'         => $doi,
					'type'       => 'dois',
					'attributes' => array(
						'event' => 'publish',
						'doi'   => $doi,
						'url'   => $url,
						'xml'   => base64_encode($payload)
					)
				)
			);
		} else {
			$jsonPayload = array(
				'data' => array(
					'type'       => 'dois',
					'attributes' => array(
						'doi' => $doi
					)
				)
			);
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
					NOTIFICATION_TYPE_ERROR,
					array('contents' => $e)
				);
			}
			return '';
		}
	}

	/**
	 * @param      $object
	 * @param bool $isSubmission
	 * @param      $press
	 * @param      $doi
	 */
	public function setDOI(
		$object, bool $isSubmission, $press, $doi) : void {
		if ($this->isTestMode($press)) {
			$doi = $this->createTestDOI($doi);
		}


		if ($isSubmission) {
			/** @var Submission $object */
			$publication = $object->getCurrentPublication();
			if (NULL !== $publication) {
				$publication->setData('pub-id::publisher-id', $doi);
				/* @var $publicationDao PublicationDAO */
				$publicationDao = DAORegistry::getDAO('PublicationDAO');
				$publicationDao->updateObject($publication);
			}
		} else {
			$object->setData('pub-id::publisher-id', $doi);
		}

		$object->setData(self::DEPOSIT_STATUS_FIELD_NAME, self::EXPORT_STATUS_REGISTERED);
		$this->updateObject($object);
	}

	/**
	 * @param array $responses
	 */
	public function createNotifications(array $responses) : void {
		$request = $this->getRequest();
		$notificationManager = new NotificationManager();
		foreach ($responses as $id => $returnValues) {
			$status = $returnValues[self::RESPONSE_KEY_STATUS];
			$title = $returnValues[self::RESPONSE_KEY_TITLE];
			$message = $returnValues[self::RESPONSE_KEY_MESSAGE];
			$type = $returnValues[self::RESPONSE_KEY_TYPE];
			$action = $returnValues[self::RESPONSE_KEY_ACTION];
			$success = in_array($status, self::DATACITE_API_RESPONSE_OK, FALSE)
				|| (empty($status) && $message === self::RESPONSE_MESSAGE_MARKED_REGISTERED);

			try {
				$decoded_message = json_decode($message, TRUE, 512, JSON_THROW_ON_ERROR);
				$log_message = $decoded_message['errors'][0]['title'];
			}
			catch (JsonException $e) {
				$log_message = $message;
			}
			$message = str_replace('{http://datacite.org/schema/kernel-4}', ' ', $log_message);

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
				$notificationManager->createTrivialNotification(
					$request->getUser()
						->getId(),
					NOTIFICATION_TYPE_SUCCESS,
					array(
						'contents' =>
							'Successfully ' . $actionType . '! ' . $type . '-id: ' . $id . '; Title: ' . $title
							. '; Status: ' . $status . '; Message: ' . $message
					)
				);
			} else {
				self::writeLog(
					'STATUS ' . $status . ' | ' . strtoupper($type) . '-ID ' . $id . ' | ' . $message,
					strtoupper($action) . ' ERROR'
				);
				$notificationManager->createTrivialNotification(
					$request->getUser()
						->getId(),
					NOTIFICATION_TYPE_ERROR,
					array(
						'contents' => 'Error! Action: ' . $action . '; ' . $type . '-id: ' . $id . '; Title: ' . $title
							. '; Status: ' . $status . '; Message: ' . $message
					)
				);
			}
		}
	}

	public static function writeLog($message, $level) : void {
		$time = new DateTime();
		$time = $time->format('d-M-Y H:i:s e');
		error_log("[$time] | $level | $message\n", 3, self::logFilePath());
	}

	public static function logFilePath() : string {

		return Config::getVar( 'files', 'files_dir' ) . '/DATACITE_ERROR.log';
	}

	public function executeCLI(		$scriptName, &$args) : void {
		fatalError('Not implemented.');
	}

	public function getName() : string {

		return 'DataciteExportPlugin';
	}

	public function getDescription() : string {

		return __( 'plugins.importexport.datacite.description' );
	}

	public function getDisplayName() : string {
		return __('plugins.importexport.datacite.displayName');
	}

	public function getPluginSettingsPrefix() : string {
		return 'datacite';
	}

	public function register($category, $path, $mainContextId = NULL) : bool {
		HookRegistry::register('LoadComponentHandler', array($this, 'setupGridHandler'));
		HookRegistry::register(
			'Templates::Management::Settings::website', array($this, 'callbackShowWebsiteSettingsTabs')
		);
		HookRegistry::register('LoadHandler', array($this, 'handleLoadRequest'));
		$success = parent::register($category, $path, $mainContextId);


		if (defined('RUNNING_UPGRADE') || !Config::getVar('general', 'installed')) {
			return $success;
		}

		if ($success && $this->getEnabled()) {
			$this->addLocaleData();
			$this->import('DataciteExportDeployment');
			foreach ($this->_getDAOs() as $dao) {
				if ($dao instanceof SchemaDAO) {
					HookRegistry::register('Schema::get::' . $dao->schemaName, array($this, 'addToSchema'));
				} else {
					HookRegistry::register(strtolower_codesafe(get_class($dao)) . '::getAdditionalFieldNames', array($this, 'addStatusField'));
				}
			}
		}

		$request = $this->getRequest();
		$templateMgr = TemplateManager::getManager($request);
		$templateMgr->addStyleSheet(
			'dataciteExportPluginStyles',
			$request->getBaseUrl() . '/' . $this->getPluginPath() . '/css/datacite-content.css',
			array(
				'priority' => STYLE_SEQUENCE_LAST,
				'contexts' => array('backend'),
				'inline'   => FALSE,
			)
		);
		$templateMgr->addJavaScript(
			'dataciteExportPluginScript',
			$request->getBaseUrl() . '/' . $this->getPluginPath() . '/js/datacite-script.js',
			array(
				'priority' => STYLE_SEQUENCE_LAST,
				'contexts' => array('backend'),
				'inline'   => FALSE,
			)
		);

		return $success;
	}

	/**
	 * @param $hookName string
	 * @param $args     array
	 */
	public function addStatusField(string $hookName, array $args) : void {
		$additionalFields =& $args[1];
		$additionalFields[] = self::DEPOSIT_STATUS_FIELD_NAME;
	}

	/**
	 * Add properties for this type of public identifier to the entity's list for
	 * storage in the database.
	 * This is used for SchemaDAO-backed entities only.
	 *
	 * @param $hookName string `Schema::get::publication`
	 * @param $params   array
	 *
	 * @return bool
	 * @see PKPPubIdPlugin::getAdditionalFieldNames()
	 */
	public function addToSchema(string $hookName, array $params) : bool {
		$schema =& $params[0];
		foreach ($this->_getObjectAdditionalSettings() as $fieldName) {
			$schema->properties->{$fieldName} = (object) [
				'type'       => 'string',
				'apiSummary' => TRUE,
				'validation' => ['nullable'],
			];
		}

		return FALSE;
	}

	/**
	 * Get a list of additional setting names that should be stored with the objects.
	 *
	 * @return array
	 */
	protected function _getObjectAdditionalSettings() : array {
		return array(self::DEPOSIT_STATUS_FIELD_NAME);
	}

	/**
	 * Get the DAOs for objects that need to be augmented with additional settings.
	 *
	 * @return array
	 */
	protected function _getDAOs() : array {
		return array(
			DAORegistry::getDAO('ChapterDAO'),
			DAORegistry::getDAO('SubmissionDAO')
		);
	}

	/**
	 * @param $object
	 */
	private function updateObject($object) : void {
		$dao = $object->getDAO();
		$dao->updateObject($object);
	}

	public function usage($scriptName) : void {
		fatalError('Not implemented.');
	}

}


