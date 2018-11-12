<?php

namespace craftnet\partners;

use Craft;
use craft\base\Volume;
use craft\db\Query;
use craft\elements\Asset;
use craft\errors\AssetDisallowedExtensionException;
use craft\errors\ImageException;
use craft\helpers\ConfigHelper;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use craft\web\Request;
use craft\web\UploadedFile;
use yii\base\Exception;
use yii\helpers\ArrayHelper;

class PartnerService
{
    private static $_instance;

    public static function getInstance()
    {
        if (!isset(self::$_instance)) {
            self::$_instance = new self();
        }

        return self::$_instance;
    }

    /**
     * @param array|PartnerProject[] $projects
     */
    public function eagerLoadProjectScreenshots(&$projects)
    {
        if (!$projects) {
            return;
        }

        $screenshots = (new PartnerProjectScreenshotsQuery())
            ->project($projects)
            ->all();

        if (!$screenshots) {
            return;
        }

        $assetsByProjectId = [];

        foreach ($screenshots as $screenshot) {
            $projectId = $screenshot['projectId'];
            unset($screenshot['projectId']);
            $assetsByProjectId[$projectId][] = new Asset($screenshot);
        }

        foreach ($projects as &$project) {
            if (array_key_exists($project->id, $assetsByProjectId)) {
                $project->screenshots = $assetsByProjectId[$project->id];
            }
        }
    }

    /**
     * This accepts an array of Capability ids or titles and returns them
     * as `Partner::getCapabilities()` would:
     *
     * ```
     * $normalized = PartnerService::getInstance()->normalizeCapabilities([
     *     1, // integer id
     *     'Full Service', // title
     *     '3', // numeric string id
     * ]);
     * // result:
     * [
     *     [1 => 'Commerce'],
     *     [2 => 'Full Service'],
     *     [3 => 'Custom Development'],
     * ]
     * ```
     *
     * @param mixed $capabilities An array, but might be an empty string when from POST
     * @return array
     */
    public function normalizeCapabilities($capabilities)
    {
        $found = [];
        $normalized = [];

        if (empty($capabilities)) {
            return $normalized;
        }

        $allCapabilities = (new PartnerCapabilitiesQuery())->asIndexedTitles()->all();

        $normalized = array_map(function($capability) use ($allCapabilities, $found) {
            $id = null;

            if (is_numeric($capability)) {
                // We have a numeric id
                $id = (int)$capability;
            } else {
                // We probably have a title so find the id if it exists
                $id = array_search($capability, $allCapabilities);
            }

            // $id could be `null`, `false`, or a numeric value
            if (!$id || in_array($id, $found)) {
                return null;
            }

            $found[] = $id;

            return [
                'id' => $id,
                'title' => $allCapabilities[$id]
            ];
        }, $capabilities);

        // return as indexed titles just like `Partner@getCapabilities()`
        return ArrayHelper::map($normalized, 'id', 'title');
    }

    /**
     * Accepts an array of location data arrays or PartnerLocation instances
     * and returns an array of PartnerLocation instances.
     *
     * @param array $locations
     * @param Partner $partner
     * @return PartnerLocation[]
     */
    public function normalizeLocations(array $locations, $partner): array
    {
        $locations = array_map(function($location) use ($partner) {
            if (!$location instanceof PartnerLocation) {
                $location = new PartnerLocation($location);
            }

            $location->partnerId = $partner->id;

            return $location;
        }, $locations);

        return $locations;
    }

    /**
     * Accepts an array of project data arrays or PartnerProject instances
     * and returns an array of PartnerProject instances.
     *
     * @param array $projects
     * @param Partner $partner
     * @param bool $eagerLoad
     * @return PartnerProject[]
     */
    public function normalizeProjects(array $projects, $partner, $eagerLoad = false): array
    {
        if (count($projects) === 0) {
            return $projects;
        }

        $ids = [];

        foreach ($projects as &$project) {
            if (!$project instanceof PartnerProject) {
                $project = new PartnerProject($project);
            }

            $project->partnerId = $partner->id;
            $ids[] = $project->id;
        }

        if ($eagerLoad) {
            $this->eagerLoadProjectScreenshots($projects);
        }

        return $projects;
    }


    /**
     * Partner volumes are created from migrations so the reliable way to get
     * folder ids is from the volume handles. Returns an array of root folder
     * ids indexed by volume handles.
     *
     * @return array
     */
    public function getVolumeFolderIds()
    {
        static $folderIds;

        if (!isset($folderIds)) {
            $rows = (new Query())
                ->select('f.id, v.handle')
                ->from('volumes v')
                ->rightJoin('volumefolders f', '[[v.id]] = [[f.volumeId]]')
                ->where(['handle' => ['partnerDocuments', 'partnerImages']])
                ->andWhere(['f.parentId' => null])// root folders only
                ->all();

            $folderIds = ArrayHelper::map($rows, 'handle', 'id');
        }

        return $folderIds;
    }

    /**
     * This volume is created by a content migration so we'll depend on the
     * volume handle to get the volumefolder id. There is only one folder
     * for this volume so a scalar query works.
     *
     * @return int Folder id or `false` if not found
     * @throws \Exception
     */
    public function getPartnerScreenshotsFolderId()
    {
        static $folderId;

        if (!isset($folderId)) {
            $folderId = (new Query())
                ->select('f.id')
                ->from('volumes v')
                ->rightJoin('volumefolders f', '[[v.id]] = [[f.volumeId]]')
                ->where(['handle' => 'partnerImages'])
                ->scalar();

            if (!$folderId) {
                throw new \Exception('Parter Screenshots volume folder does not exist. Need to run migrations?');
            }
        }

        return $folderId;
    }

    /**
     * Returns an array representation of a Partner with all related data
     * suitable for a public JSON response.
     *
     * @param Partner $partner
     * @return array
     */
    public function serializePartner($partner)
    {
        $strings = [
            'businessName',
            'primaryContactName',
            'primaryContactEmail',
            'primaryContactPhone',
            'fullBio',
            'shortBio',
            'agencySize',
            'expertise',
            'region',
            'websiteSlug',
            'website',
        ];

        $booleans = [
            'enabled',
            'hasFullTimeDev',
            'isCraftVerified',
            'isCommerceVerified',
            'isEnterpriseVerified',
            'isRegisteredBusiness',
        ];

        $others = [
            'id',
            'ownerId',
            'capabilities',
            'locations',
            'projects',
            'verificationStartDate',
        ];

        $data = $partner->getAttributes(array_merge($strings, $booleans, $others));

        foreach ($strings as $stringAttribute) {
            $data[$stringAttribute] = (string)$data[$stringAttribute];
        }

        foreach ($booleans as $booleanAttribute) {
            $data[$booleanAttribute] = (bool)$data[$booleanAttribute];
        }

        // logo
        $logo = $partner->getLogo();

        $data['logo'] = [
            'id' => $logo ? $logo->id : null,
            'url' => $logo ? $logo->getUrl() : '',
        ];

        // capabilities - titles only
        $data['capabilities'] = array_values($data['capabilities']);

        // locations
        /** @var PartnerLocation $location */
        foreach ($data['locations'] as $i => $location) {
            $data['locations'][$i] = $location->getAttributes([
                'id',
                'title',
                'addressLine1',
                'addressLine2',
                'city',
                'state',
                'zip',
                'country',
                'phone',
                'email',
            ]);
        }

        // For Craft ID Vue, the array must not be indexed by id
        $data['locations'] = array_values($data['locations']);

        // projects
        $this->eagerLoadProjectScreenshots($data['projects']);

        /** @var PartnerProject $project */
        foreach ($data['projects'] as $i => $project) {
            $data['projects'][$i] = $project->getAttributes(['id', 'name', 'role', 'url', 'screenshots']);

            if (is_array($data['projects'][$i]['screenshots'])) {
                /** @var Asset $screenshot */
                foreach ($data['projects'][$i]['screenshots'] as $ii => $screenshot) {
                    $data['projects'][$i]['screenshots'][$ii] = [
                        'id' => $screenshot->id,
                        'url' => $screenshot->getUrl(),
                    ];
                }
            } else {
                $data['projects'][$i]['screenshots'] = [];
            }
        }

        // For Craft ID Vue, the array must not be indexed by id
        $data['projects'] = array_values($data['projects']);

        return $data;
    }

    /**
     * @param Partner $partner
     * @return array
     */
    public function getSerializedPartnerErrors(Partner $partner)
    {
        $errors = $partner->getErrors();

        $locationErrors = [];
        /** @var PartnerLocation $location */
        foreach ($partner->getLocations() as $location) {
            $locationErrors[] = $location->getErrors();
        }

        if ($locationErrors) {
            $errors['locations'] = $locationErrors;
        }

        $projectErrors = [];
        /** @var PartnerProject $project */
        foreach ($partner->getProjects() as $project) {
            $projectErrors[] = $project->getErrors();
        }

        if ($projectErrors) {
            $errors['projects'] = $projectErrors;
        }

        return $errors;
    }

    /**
     * @param Partner $partner
     * @param Request $request
     * @param array $properties
     */
    public function mergeRequestParams(Partner $partner, Request $request, array $properties)
    {
        foreach ($properties as $property) {
            switch ($property) {
                case 'ownerId':
                    $partner->ownerId = ((array)$request->getBodyParam('ownerId'))[0];
                    break;

                case 'capabilities':
                    $partner->setCapabilities($request->getBodyParam('capabilities', []));
                    break;

                case 'locations':
                    $partner->setLocationsFromPost($request->getBodyParam('locations', []));
                    break;

                case 'projects':
                    $partner->setProjectsFromPost($request->getBodyParam('projects', []));
                    break;

                case 'verificationStartDate':
                    $partner->setVerificationStartDateFromPost($request->getBodyParam('verificationStartDate'));
                    break;

                case 'hasFullTimeDev':
                case 'isRegisteredBusiness':
                    // There must be a built-in Yii way to cast 'true' and 'false'
                    // to boolean in validation rules but for now...
                    $value = $request->getBodyParam($property);
                    $partner->{$property} = filter_var($value, FILTER_VALIDATE_BOOLEAN);
                    break;

                case 'websiteSlug':
                    $partner->websiteSlug = $request->getBodyParam($property);

                    if (empty($partner->websiteSlug) && !empty($partner->businessName)) {
                        $partner->websiteSlug = ElementHelper::createSlug($partner->businessName);
                    }
                    break;

                case 'logoAssetId':
                    $value = $request->getBodyParam('logoAssetId');
                    $id = is_array($value) ? $value[0] : null;

                    $partner->logoAssetId = $id === 'null' ? null : $id;
                    break;

                default:
                    $partner->{$property} = $request->getBodyParam($property);
                    break;
            }
        }
    }

    /**
     * Derived from `PluginsController`
     *
     * @param Partner $partner
     * @return Asset[]
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \yii\base\Exception
     */
    public function handleUploadedScreenshots(Partner $partner)
    {
        $screenshots = [];
        $allowedFileExtensions = ['jp2', 'jpeg', 'jpg', 'jpx', 'png'];
        $imageService = Craft::$app->getImages();
        $assetsService = Craft::$app->getAssets();
        $volumesService = Craft::$app->getVolumes();

        $iniMaxUpload = ConfigHelper::sizeInBytes(ini_get('upload_max_filesize'));
        $configMaxUpload = Craft::$app->getConfig()->getGeneral()->maxUploadFileSize;
        $maxUpload = min($iniMaxUpload, $configMaxUpload);
        $maxUploadM = round($maxUpload / 1000 / 1000);

        $screenshotFiles = UploadedFile::getInstancesByName('screenshots');

        foreach ($screenshotFiles as $screenshotFile) {
            if ($screenshotFile->error != UPLOAD_ERR_OK) {
                if ($screenshotFile->error == UPLOAD_ERR_INI_SIZE) {
                    throw new Exception('Couldn’t upload screenshot because it exceeds the limit of ' . $maxUploadM . 'MB.');
                }

                throw new Exception('Couldn’t upload screenshot. (Error ' . $screenshotFile->error . ')');
            }

            if ($screenshotFile->size > $maxUpload) {
                throw new Exception('Couldn’t upload screenshot because it exceeds the limit of ' . $maxUploadM . 'MB.');
            }

            $extension = $screenshotFile->getExtension();

            if (!in_array(strtolower($extension), $allowedFileExtensions, true)) {
                throw new AssetDisallowedExtensionException("Screenshot was not uploaded because extension “{$extension}” is not allowed.");
            }

            $handle = $partner->id;
            $tempPath = Craft::$app->getPath()->getTempPath() . "/screenshot-{$handle}-" . StringHelper::randomString() . '.' . $screenshotFile->getExtension();
            move_uploaded_file($screenshotFile->tempName, $tempPath);

            if (!$imageService->checkMemoryForImage($tempPath)) {
                throw new ImageException(Craft::t('app',
                    'Not enough memory available to perform this image operation.'));
            }

            $imageService->cleanImage($tempPath);

            // Save as an asset

            /** @var Volume $volume */
            $volume = $volumesService->getVolumeByHandle('partnerImages');
            $volumeId = $volumesService->ensureTopFolder($volume);

            $subpath = '/' . $handle;

            $folder = $assetsService->findFolder([
                'volumeId' => $volumeId,
                'path' => $subpath . '/'
            ]);

            if (!$folder) {
                $folderId = $assetsService->ensureFolderByFullPathAndVolume($subpath, $volume);
            } else {
                $folderId = $folder->id;
            }

            $targetFilename = $screenshotFile->name;

            $screenshot = new Asset([
                'title' => $partner->businessName,
                'tempFilePath' => $tempPath,
                'newLocation' => "{folder:{$folderId}}" . $targetFilename,
                'avoidFilenameConflicts' => true,
            ]);

            $screenshot->validate(['newLocation']);

            if ($screenshot->hasErrors() || !Craft::$app->getElements()->saveElement($screenshot, false)) {
                throw new Exception('Could not save image asset: ' . implode(', ', $screenshot->getErrorSummary(true)));
            }

            $screenshots[] = $screenshot;
        }

        return $screenshots;
    }

    /**
     * I am so sorry for the copy/paste. In a hurry.
     *
     * @param Partner $partner
     * @return Asset
     * @throws AssetDisallowedExtensionException
     * @throws Exception
     * @throws ImageException
     * @throws \Throwable
     * @throws \craft\errors\ElementNotFoundException
     * @throws \craft\errors\VolumeException
     */
    public function handleUploadedLogo(Partner $partner)
    {
        $allowedFileExtensions = ['svg'];
        $imageService = Craft::$app->getImages();
        $assetsService = Craft::$app->getAssets();
        $volumesService = Craft::$app->getVolumes();

        $iniMaxUpload = ConfigHelper::sizeInBytes(ini_get('upload_max_filesize'));
        $configMaxUpload = Craft::$app->getConfig()->getGeneral()->maxUploadFileSize;
        $maxUpload = min($iniMaxUpload, $configMaxUpload);
        $maxUploadM = round($maxUpload / 1000 / 1000);

        $logoFile = UploadedFile::getInstanceByName('logo');

        if (!$logoFile) {
            return null;
        }

        if ($logoFile->error != UPLOAD_ERR_OK) {
            if ($logoFile->error == UPLOAD_ERR_INI_SIZE) {
                $partner->addError('logo', 'Couldn’t upload logo because it exceeds the limit of ' . $maxUploadM . 'MB.');
            } else {
                $partner->addError('logo', 'Couldn’t upload logo. (Error ' . $logoFile->error . ')');
            }

            return null;
        }

        if ($logoFile->size > $maxUpload) {
            $partner->addError('logo', 'Couldn’t upload logo because it exceeds the limit of ' . $maxUploadM . 'MB.');
            return null;
        }

        $extension = $logoFile->getExtension();

        if (!in_array(strtolower($extension), $allowedFileExtensions, true)) {
            $partner->addError('logo', "Logo was not uploaded because extension “{$extension}” is not allowed.");
            return null;
        }

        $handle = $partner->id;
        $tempPath = Craft::$app->getPath()->getTempPath() . "/logo-{$handle}-" . StringHelper::randomString() . '.' . $logoFile->getExtension();
        move_uploaded_file($logoFile->tempName, $tempPath);

        if (!$imageService->checkMemoryForImage($tempPath)) {
            $partner->addError('logo', 'Not enough memory to perform image operation.');
            return null;
        }

        $imageService->cleanImage($tempPath);

        // Save as an asset

        /** @var Volume $volume */
        $volume = $volumesService->getVolumeByHandle('partnerImages');
        $volumeId = $volumesService->ensureTopFolder($volume);

        $subpath = '/' . $handle;

        $folder = $assetsService->findFolder([
            'volumeId' => $volumeId,
            'path' => $subpath . '/'
        ]);

        if (!$folder) {
            $folderId = $assetsService->ensureFolderByFullPathAndVolume($subpath, $volume);
        } else {
            $folderId = $folder->id;
        }

        $targetFilename = $logoFile->name;

        $logo = new Asset([
            'title' => $partner->businessName,
            'tempFilePath' => $tempPath,
            'newLocation' => "{folder:{$folderId}}" . $targetFilename,
            'avoidFilenameConflicts' => true,
        ]);

        $logo->validate(['newLocation']);

        if ($logo->hasErrors() || !Craft::$app->getElements()->saveElement($logo, false)) {
            throw new Exception('Could not save image asset: ' . implode(', ', $logo->getErrorSummary(true)));
        }

        return $logo;
    }
}