<?php

declare(strict_types=1);

use YiiRocks\Voyti\Model\Form\Settings\ConsentForm;
use Yiisoft\FormModel\Field;
use Yiisoft\Html\Html;
use Yiisoft\Translator\TranslatorInterface;
use Yiisoft\View\WebView;
use Yiisoft\Yii\View\Renderer\Csrf;

/**
 * @var WebView $this
 * @var ConsentForm $form
 * @var array{
 *   formSubmitUrl: string,
 * } $data
 * @var TranslatorInterface $translator
 * @var Csrf $csrf
 */

/** @psalm-suppress InvalidScope */
$this->setTitle($translator->translate('voyti.view.privacy.anonymize_data', category: 'voyti-gdpr'));

echo Html::div()->open();
echo Html::H1($translator->translate('voyti.view.privacy.anonymize_data', category: 'voyti-gdpr'));

echo Html::p()->class('alert alert-danger')->open();
echo $translator->translate('voyti.view.privacy.anonymize_warning', category: 'voyti-gdpr');
echo Html::p()->close();

echo Html::form()
    ->post($data['formSubmitUrl'])
    ->csrf($csrf)
    ->open();

echo Field::errorSummary($form);

echo Field::password($form, 'password')->tabIndex(1);

echo Field::buttonGroup()
    ->buttonsData([
        [$translator->translate('voyti.view.reset_button'), 'type' => 'reset', 'tabindex' => 3],
        [$translator->translate('voyti.view.privacy.anonymize_button', category: 'voyti-gdpr'), 'type' => 'submit', 'class' => 'btn btn-danger', 'tabindex' => 2],
    ]);

echo Html::form()->close();
echo Html::div()->close();
