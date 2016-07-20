<?php
/**
 * @link      http://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license   http://craftcms.com/license
 */

namespace craft\app\controllers;

use Craft;
use craft\app\elements\Entry;
use craft\app\helpers\Json;
use craft\app\helpers\Url;
use craft\app\models\EntryType;
use craft\app\models\Section;
use craft\app\models\SectionLocale;
use craft\app\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The SectionsController class is a controller that handles various section and entry type related tasks such as
 * displaying, saving, deleting and reordering them in the control panel.
 *
 * Note that all actions in this controller require administrator access in order to execute.
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since  3.0
 */
class SectionsController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init()
    {
        // All section actions require an admin
        $this->requireAdmin();
    }

    /**
     * Sections index.
     *
     * @param array $variables
     *
     * @return string The rendering result
     */
    public function actionIndex(array $variables = [])
    {
        $variables['sections'] = Craft::$app->getSections()->getAllSections();

        return $this->renderTemplate('settings/sections/_index', $variables);
    }

    /**
     * Edit a section.
     *
     * @param integer $sectionId The section’s id, if any.
     * @param Section $section   The section being edited, if there were any validation errors.
     *
     * @return string The rendering result
     * @throws NotFoundHttpException if the requested section cannot be found
     * @throws BadRequestHttpException if attempting to do something not allowed by the current Craft edition
     */
    public function actionEditSection($sectionId = null, Section $section = null)
    {
        $variables = [
            'sectionId' => $sectionId,
            'brandNewSection' => false
        ];

        if ($sectionId !== null) {
            if ($section === null) {
                $section = Craft::$app->getSections()->getSectionById($sectionId);

                if (!$section) {
                    throw new NotFoundHttpException('Section not found');
                }
            }

            $variables['title'] = $section->name;
        } else {
            if ($section === null) {
                $section = new Section();
                $variables['brandNewSection'] = true;
            }

            $variables['title'] = Craft::t('app', 'Create a new section');
        }

        $types = [
            Section::TYPE_SINGLE,
            Section::TYPE_CHANNEL,
            Section::TYPE_STRUCTURE
        ];
        $typeOptions = [];

        // Get these strings to be caught by our translation util:
        // Craft::t('app', 'Channel') Craft::t('app', 'Structure') Craft::t('app', 'Single')

        foreach ($types as $type) {
            $typeOptions[$type] = Craft::t('app', ucfirst($type));
        }

        if (!$typeOptions) {
            throw new BadRequestHttpException('Craft Client or Pro Edition is required to create any additional sections');
        }

        if (!$section->type) {
            $section->type = Section::TYPE_CHANNEL;
        }

        $variables['section'] = $section;
        $variables['typeOptions'] = $typeOptions;

        $variables['canBeHomepage'] = (
            ($section->id && $section->isHomepage()) ||
            (!Craft::$app->getSections()->doesHomepageExist())
        );

        $variables['crumbs'] = [
            [
                'label' => Craft::t('app', 'Settings'),
                'url' => Url::getUrl('settings')
            ],
            [
                'label' => Craft::t('app', 'Sections'),
                'url' => Url::getUrl('settings/sections')
            ],
        ];

        return $this->renderTemplate('settings/sections/_edit', $variables);
    }

    /**
     * Saves a section.
     *
     * @return Response|null
     */
    public function actionSaveSection()
    {
        $this->requirePostRequest();

        $section = new Section();

        // Shared attributes
        $section->id = Craft::$app->getRequest()->getBodyParam('sectionId');
        $section->name = Craft::$app->getRequest()->getBodyParam('name');
        $section->handle = Craft::$app->getRequest()->getBodyParam('handle');
        $section->type = Craft::$app->getRequest()->getBodyParam('type');
        $section->enableVersioning = Craft::$app->getRequest()->getBodyParam('enableVersioning', true);

        // Type-specific attributes
        $section->hasUrls = (bool)Craft::$app->getRequest()->getBodyParam('types.'.$section->type.'.hasUrls', true);
        $section->template = Craft::$app->getRequest()->getBodyParam('types.'.$section->type.'.template');
        $section->maxLevels = Craft::$app->getRequest()->getBodyParam('types.'.$section->type.'.maxLevels');

        // Locale-specific attributes
        $locales = [];

        if (Craft::$app->isLocalized()) {
            $localeIds = Craft::$app->getRequest()->getBodyParam('locales', []);
        } else {
            $primaryLocaleId = Craft::$app->getI18n()->getPrimarySiteLocaleId();
            $localeIds = [$primaryLocaleId];
        }

        $isHomepage = ($section->type == Section::TYPE_SINGLE && Craft::$app->getRequest()->getBodyParam('types.'.$section->type.'.homepage'));

        foreach ($localeIds as $localeId) {
            if ($isHomepage) {
                $urlFormat = '__home__';
                $nestedUrlFormat = null;
            } else {
                $urlFormat = Craft::$app->getRequest()->getBodyParam('types.'.$section->type.'.urlFormat.'.$localeId);
                $nestedUrlFormat = Craft::$app->getRequest()->getBodyParam('types.'.$section->type.'.nestedUrlFormat.'.$localeId);
            }

            $locales[$localeId] = new SectionLocale([
                'locale' => $localeId,
                'enabledByDefault' => (bool)Craft::$app->getRequest()->getBodyParam('defaultLocaleStatuses.'.$localeId),
                'urlFormat' => $urlFormat,
                'nestedUrlFormat' => $nestedUrlFormat,
            ]);
        }

        $section->setLocales($locales);

        // Save it
        if (Craft::$app->getSections()->saveSection($section)) {
            Craft::$app->getSession()->setNotice(Craft::t('app', 'Section saved.'));

            return $this->redirectToPostedUrl($section);
        }

        Craft::$app->getSession()->setError(Craft::t('app', 'Couldn’t save section.'));

        // Send the section back to the template
        Craft::$app->getUrlManager()->setRouteParams([
            'section' => $section
        ]);

        return null;
    }

    /**
     * Deletes a section.
     *
     * @return Response
     */
    public function actionDeleteSection()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        $sectionId = Craft::$app->getRequest()->getRequiredBodyParam('id');

        Craft::$app->getSections()->deleteSectionById($sectionId);

        return $this->asJson(['success' => true]);
    }

    // Entry Types

    /**
     * Entry types index
     *
     * @param integer $sectionId The ID of the section whose entry types we’re listing
     *
     * @return string The rendering result
     * @throws NotFoundHttpException if the requested section cannot be found
     */
    public function actionEntryTypesIndex($sectionId)
    {
        $section = Craft::$app->getSections()->getSectionById($sectionId);

        if ($section === null) {
            throw new NotFoundHttpException('Section not found');
        }

        $crumbs = [
            [
                'label' => Craft::t('app', 'Settings'),
                'url' => Url::getUrl('settings')
            ],
            [
                'label' => Craft::t('app', 'Sections'),
                'url' => Url::getUrl('settings/sections')
            ],
            [
                'label' => Craft::t('site', $section->name),
                'url' => Url::getUrl('settings/sections/'.$section->id)
            ],
        ];

        $title = Craft::t('app', '{section} Entry Types',
            ['section' => Craft::t('site', $section->name)]);

        return $this->renderTemplate('settings/sections/_entrytypes/index', [
            'sectionId' => $sectionId,
            'section' => $section,
            'crumbs' => $crumbs,
            'title' => $title,
        ]);
    }

    /**
     * Edit an entry type
     *
     * @param integer   $sectionId   The section’s ID.
     * @param integer   $entryTypeId The entry type’s ID, if any.
     * @param EntryType $entryType   The entry type being edited, if there were any validation errors.
     *
     * @return string The rendering result
     * @throws NotFoundHttpException if the requested section/entry type cannot be found
     * @throws BadRequestHttpException if the requested entry type does not belong to the requested section
     */
    public function actionEditEntryType($sectionId, $entryTypeId = null, EntryType $entryType = null)
    {
        $section = Craft::$app->getSections()->getSectionById($sectionId);

        if (!$section) {
            throw new NotFoundHttpException('Section not found');
        }

        if ($entryTypeId !== null) {
            if ($entryType === null) {
                $entryType = Craft::$app->getSections()->getEntryTypeById($entryTypeId);

                if (!$entryType) {
                    throw new NotFoundHttpException('Entry type not found');
                }

                if ($entryType->sectionId != $section->id) {
                    throw new BadRequestHttpException('Entry type does not belong to the requested section');
                }
            }

            $title = $entryType->name;
        } else {
            if ($entryType === null) {
                $entryType = new EntryType();
                $entryType->sectionId = $section->id;
            }

            $title = Craft::t('app', 'Create a new {section} entry type',
                ['section' => Craft::t('site', $section->name)]);
        }

        $crumbs = [
            [
                'label' => Craft::t('app', 'Settings'),
                'url' => Url::getUrl('settings')
            ],
            [
                'label' => Craft::t('app', 'Sections'),
                'url' => Url::getUrl('settings/sections')
            ],
            [
                'label' => $section->name,
                'url' => Url::getUrl('settings/sections/'.$section->id)
            ],
            [
                'label' => Craft::t('app', 'Entry Types'),
                'url' => Url::getUrl('settings/sections/'.$sectionId.'/entrytypes')
            ],
        ];

        return $this->renderTemplate('settings/sections/_entrytypes/edit', [
            'sectionId' => $sectionId,
            'section' => $section,
            'entryTypeId' => $entryTypeId,
            'entryType' => $entryType,
            'title' => $title,
            'crumbs' => $crumbs
        ]);
    }

    /**
     * Saves an entry type.
     *
     * @return Response|null
     * @throws NotFoundHttpException if the requested entry type cannot be found
     */
    public function actionSaveEntryType()
    {
        $this->requirePostRequest();

        $entryTypeId = Craft::$app->getRequest()->getBodyParam('entryTypeId');

        if ($entryTypeId) {
            $entryType = Craft::$app->getSections()->getEntryTypeById($entryTypeId);

            if (!$entryType) {
                throw new NotFoundHttpException('Entry type not found');
            }
        } else {
            $entryType = new EntryType();
        }

        // Set the simple stuff
        $entryType->sectionId = Craft::$app->getRequest()->getRequiredBodyParam('sectionId', $entryType->sectionId);
        $entryType->name = Craft::$app->getRequest()->getBodyParam('name', $entryType->name);
        $entryType->handle = Craft::$app->getRequest()->getBodyParam('handle', $entryType->handle);
        $entryType->hasTitleField = (bool)Craft::$app->getRequest()->getBodyParam('hasTitleField', $entryType->hasTitleField);
        $entryType->titleLabel = Craft::$app->getRequest()->getBodyParam('titleLabel', $entryType->titleLabel);
        $entryType->titleFormat = Craft::$app->getRequest()->getBodyParam('titleFormat', $entryType->titleFormat);

        // Set the field layout
        $fieldLayout = Craft::$app->getFields()->assembleLayoutFromPost();
        $fieldLayout->type = Entry::className();
        $entryType->setFieldLayout($fieldLayout);

        // Save it
        if (Craft::$app->getSections()->saveEntryType($entryType)) {
            Craft::$app->getSession()->setNotice(Craft::t('app', 'Entry type saved.'));

            return $this->redirectToPostedUrl($entryType);
        }

        Craft::$app->getSession()->setError(Craft::t('app', 'Couldn’t save entry type.'));

        // Send the entry type back to the template
        Craft::$app->getUrlManager()->setRouteParams([
            'entryType' => $entryType
        ]);

        return null;
    }

    /**
     * Reorders entry types.
     *
     * @return Response
     */
    public function actionReorderEntryTypes()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        $entryTypeIds = Json::decode(Craft::$app->getRequest()->getRequiredBodyParam('ids'));
        Craft::$app->getSections()->reorderEntryTypes($entryTypeIds);

        return $this->asJson(['success' => true]);
    }

    /**
     * Deletes an entry type.
     *
     * @return Response
     */
    public function actionDeleteEntryType()
    {
        $this->requirePostRequest();
        $this->requireAjaxRequest();

        $entryTypeId = Craft::$app->getRequest()->getRequiredBodyParam('id');

        Craft::$app->getSections()->deleteEntryTypeById($entryTypeId);

        return $this->asJson(['success' => true]);
    }
}
