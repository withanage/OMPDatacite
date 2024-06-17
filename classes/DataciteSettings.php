<?php

/**
 * @file plugins/generic/datacite/classes/DataciteSetting.php
 *
 * Copyright (c) 2014-2023 Simon Fraser University
 * Copyright (c) 2003-2023 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DataciteSettings
 *
 * @ingroup plugins_generic_datacite_classes
 *
 * @brief Setting management class to handle schema, fields, validation, etc. for Datacite plugin
 */

namespace APP\plugins\generic\datacite\classes;

use Illuminate\Validation\Validator;
use PKP\components\forms\FieldHTML;
use PKP\components\forms\FieldOptions;
use PKP\components\forms\FieldText;
use PKP\context\Context;
use PKP\doi\RegistrationAgencySettings;
use stdClass;

class DataciteSettings extends RegistrationAgencySettings
{
    public const KEY_USERNAME = 'username';
    public const KEY_PASSWORD = 'password';
    public const KEY_TEST_MODE = 'testMode';
    public const KEY_TEST_USERNAMER = 'testUsername';
    public const KEY_TEST_PASSWORD = 'testPassword';
    public const KEY_TEST_DOI_PREFIX = 'testDOIPrefix';
    public const KEY_ONLY_WITH_LANDINGPAGE = 'onlyWithLandingPage';
    public function getSchema(): stdClass
    {
        return (object) [
            'title' => 'Datacite Plugin',
            'description' => 'Registration agency plugin for Datacite',
            'type' => 'object',
            'required' => [],
            'properties' => (object) [

                self::KEY_USERNAME => (object) [
                    'type' => 'string',
                    'validation' => ['nullable', 'max:50']
                ],
                self::KEY_PASSWORD => (object) [
                    'type' => 'string',
                    'validation' => ['nullable', 'max:50']
                ],
                self::KEY_TEST_MODE => (object) [
                    'type' => 'boolean',
                    'validation' => ['nullable']
                ],
                self::KEY_TEST_USERNAMER => (object) [
                    'type' => 'string',
                    'validation' => ['nullable', 'max:50']
                ],
                self::KEY_TEST_PASSWORD => (object) [
                    'type' => 'string',
                    'validation' => ['nullable', 'max:50']
                ],
                self::KEY_TEST_DOI_PREFIX => (object) [
                    'type' => 'string',
                    'validation' => ['nullable', 'max:50']
                ],
                self::KEY_ONLY_WITH_LANDINGPAGE => (object) [
                    'type' => 'boolean',
                    'validation' => ['nullable']
                ],
            ],
        ];
    }

    /**
     * @inheritDoc
     */
    public function getFields(Context $context): array
    {
        return [
            new FieldHTML('preamble', [
                'label' => __('plugins.importexport.datacite.settings.label'),
                'description' => $this->_getPreambleText(),
            ]),
            new FieldText(self::KEY_USERNAME, [
                'label' => __('plugins.importexport.datacite.settings.form.username'),
                'value' => $this->agencyPlugin->getSetting($context->getId(), self::KEY_USERNAME),
            ]),
            new FieldText(self::KEY_PASSWORD, [
                'label' => __('plugins.importexport.datacite.settings.form.password'),
                'description' => __('plugins.importexport.datacite.settings.form.password.description'),
                'inputType' => 'password',
                'value' => $this->agencyPlugin->getSetting($context->getId(), self::KEY_PASSWORD),
            ]),
            new FieldOptions(self::KEY_ONLY_WITH_LANDINGPAGE, [
                'label' => __('plugins.importexport.datacite.settings.form.onlyWithLandingPage.label'),
                'options' => [
                    ['value' => true, 'label' => __('plugins.importexport.datacite.settings.form.onlyWithLandingPage.description')],
                ],
                'value' => $this->agencyPlugin->getSetting($context->getId(), self::KEY_ONLY_WITH_LANDINGPAGE),
            ]),
            new FieldOptions(self::KEY_TEST_MODE, [
                'label' => __('plugins.importexport.common.settings.form.testMode.label'),
                'options' => [
                    ['value' => true, 'label' => __('plugins.importexport.datacite.settings.form.testMode.description')],
                ],
                'value' => $this->agencyPlugin->getSetting($context->getId(), self::KEY_TEST_MODE),
            ]),
            new FieldText(self::KEY_TEST_USERNAMER, [
                'label' => __('plugins.importexport.datacite.settings.form.testUsername'),
                'value' => $this->agencyPlugin->getSetting($context->getId(), self::KEY_TEST_USERNAMER),
            ]),
            new FieldText(self::KEY_TEST_PASSWORD, [
                'label' => __('plugins.importexport.datacite.settings.form.testPassword'),
                'inputType' => 'password',
                'value' => $this->agencyPlugin->getSetting($context->getId(), self::KEY_TEST_PASSWORD),
            ]),
            new FieldText(self::KEY_TEST_DOI_PREFIX, [
                'label' => __('plugins.importexport.datacite.settings.form.testDOIPrefix'),
                'value' => $this->agencyPlugin->getSetting($context->getId(), self::KEY_TEST_DOI_PREFIX),
            ]),
        ];
    }

    /**
     * @inheritDoc
     */
    protected function addValidationChecks(Validator &$validator, $props): void
    {
        // If in test mode, the test DOI prefix must be set
        $validator->after(function (Validator $validator) use ($props) {
            if ($props[self::KEY_TEST_MODE]) {
                if (empty($props[self::KEY_TEST_DOI_PREFIX])) {
                    $validator->errors()->add(self::KEY_TEST_DOI_PREFIX, __('plugins.importexport.datacite.settings.form.testDOIPrefixRequired'));
                }
            }
        });

        // If username exists, there will be the possibility to register from within OMP,
        // so the test username must exist too
        $validator->after(function (Validator $validator) use ($props) {
            if (!empty($props[self::KEY_USERNAME]) && empty($props[self::KEY_TEST_USERNAMER])) {
                $validator->errors()->add(self::KEY_TEST_USERNAMER, __('plugins.importexport.datacite.settings.form.testUsernameRequired'));
            }
        });
    }

    protected function _getPreambleText(): string
    {
        $text = '<p>' . __('plugins.importexport.datacite.settings.description') . '</p>';
        $text .= '<p>' . __('plugins.importexport.datacite.intro') . '</p>';

        return $text;
    }
}