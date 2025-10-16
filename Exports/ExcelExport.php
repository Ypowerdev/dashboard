<?php

namespace App\Exports;

use App\Exports\Sheets\OivEWSheet;
use App\Exports\Sheets\OivFactSheet;
use App\Exports\Sheets\OivKtSheet;
use App\Exports\Sheets\OivPlanSheet;
use App\Exports\Sheets\OivResourcesSheet;
use App\Exports\Sheets\SmgActionsSheet;
use App\Exports\Sheets\SmgCultureSheet;
use App\Exports\Sheets\SmgDailySheet;
use App\Models\Library\ConstructionStagesLibrary;
use App\Models\Library\ControlPointsLibrary;
use App\Models\ObjectModel;
use App\Models\User;
use App\Models\UserRoleRight;
use App\Services\ExcelServices\OivEWDataService;
use App\Services\ExcelServices\OivKtDataService;
use App\Services\ExcelServices\OivPlanDataService;
use App\Services\ExcelServices\OivResourcesDataService;
use App\Services\ExcelServices\SmgActionsDataService;
use App\Services\ExcelServices\SmgCultureDataService;
use App\Services\ExcelServices\SmgDailyReportService;
use App\Services\ExcelServices\OivFactDataService;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ExcelExport implements WithMultipleSheets
{
    public function __construct(private readonly int $userId) {}

    /**
     * Метод формирует листы Excel с данными из БД
     *
     * @return array
     * @throws \Exception
     */
    public function sheets(): array
    {
        // TODO Удалить после тестирования
        ini_set('memory_limit', '2G');
        ini_set('max_execution_time', 600);
        ini_set('max_input_values', 100000);

        $smgDataService = app(SmgDailyReportService::class);
        $smgActionsDataService = app(SmgActionsDataService::class);
        $smgCultureDataService = app(SmgCultureDataService::class);
        //$oivResourcesDataService = app(OivResourcesDataService::class);
        //$oivEWDataService = app(OivEWDataService::class);
        //$oivPlanDataService = app(OivPlanDataService::class);
        //$oivFactDataService = app(OivFactDataService::class);

        // TODO Контрольные точки не выгружаем - пока комметируем (впоследствии может пригодится)
        //$oivKtDataService = app(OivKtDataService::class);

        $user = $this->userId ? User::find($this->userId) : Auth::user();
        if (!$user) {
            Log::error('Не удалось определить пользователя для экспорта. Экспорт прерван.');
            throw new \Exception('Не удалось определить пользователя для импорта.');
        }

        $objects = ObjectModel::whereIn('id', UserRoleRight::getAllowedObjectToUpdate($this->userId))
            ->with([
                'region',
                'finSource',
                'customer',
                'developer',
                'contractor',
                'oksStatusLibrary',
                'monitorTechnica',
                'monitorPeople',
                'fno',
                'objectConstructionStages'
            ])->get();

        // Собираем все уникальные строительные этапы из всех объектов
        //$allConstructionStages = $this->collectAllConstructionStages($objects);

        // Собираем все уникальные контрольные точки из всех объектов
        //$allControlPoints = $this->collectAllControlPoints($objects);

        //$ktData = $oivKtDataService->getKtData($objects, $user);
        //[$ewData, $ewDates] = $oivEWDataService->getEWData($objects, $user);

        return [
            new SmgDailySheet($smgDataService->getDailyData($objects, $user)),
           // new SmgActionsSheet($smgActionsDataService->getActionsData()),
           // new SmgCultureSheet($smgCultureDataService->getCultureData()),
           // new OivResourcesSheet($oivResourcesDataService->getResourcesData()),
           // new OivEWSheet($ewData, $ewDates),
           // new OivPlanSheet($oivPlanDataService->getPlanData($objects, $user, $allConstructionStages), $allConstructionStages),
           // new OivFactSheet($oivFactDataService->getFactData($objects, $user, $allConstructionStages), $allConstructionStages),

            // TODO Контрольные точки не выгружаем - пока комметируем (впоследствии может пригодится)
            // new OivKtSheet($ktData, $allControlPoints),

        ];
    }

    /**
     * Собирает все уникальные строительные этапы из всех объектов
     * @param $objects
     * @return array
     */
    private function collectAllConstructionStages($objects): array
    {
        $stageIds = [];
        // собираем все id construction_stages
        foreach ($objects as $object) {
            $ids = $object->objectConstructionStages->pluck('construction_stages_library_id')->toArray();
            $stageIds = array_merge($stageIds, $ids);
        }

        return ConstructionStagesLibrary::whereIn('id', array_unique($stageIds))->with('parent')->get()->toArray();
    }

    /**
     * Собирает все уникальные контрольные точки из всех объектов
     * @param $objects
     * @return array
     */
    private function collectAllControlPoints($objects): array
    {
        $pointIds = [];
        // собираем все id контрольных точек
        foreach ($objects as $object) {

            $ids = $object->controlPoints->pluck('stage_id')->toArray();

            $pointIds = array_merge($pointIds, $ids);
        }

        return ControlPointsLibrary::whereIn('id', array_unique($pointIds))->with('parent')->get()->toArray();
    }
}
