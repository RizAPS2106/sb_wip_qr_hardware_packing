<?php

namespace App\Http\Livewire;

use Livewire\Component;
use Livewire\WithPagination;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use App\Models\Nds\Numbering;
use App\Models\Nds\OutputPacking;
use App\Models\SignalBit\MasterPlan;
use App\Models\SignalBit\Rft;
use App\Models\SignalBit\Defect;
use App\Models\SignalBit\Rework as ReworkModel;
use Carbon\Carbon;
use DB;

class Rework extends Component
{
    use WithPagination;

    protected $paginationTheme = 'bootstrap';

    // filters
    public $orderInfo;
    public $orderWsDetailSizes;
    public $searchDefect;
    public $searchRework;

    // defect position
    public $defectImage;
    public $defectPositionX;
    public $defectPositionY;

    // defect list
    public $allDefectListFilter;
    public $allDefectImage;
    public $allDefectPosition;
    // public $allDefectList;

    // mass rework
    public $massQty;
    public $massSize;
    public $massDefectType;
    public $massDefectTypeName;
    public $massDefectArea;
    public $massDefectAreaName;
    public $massSelectedDefect;

    public $info;

    public $output;
    public $rework;
    public $sizeInputText;
    public $noCutInput;
    public $numberingInput;

    public $rapidRework;
    public $rapidReworkCount;

    protected $rules = [
        'sizeInput' => 'required',
        'noCutInput' => 'required',
        'numberingInput' => 'required|unique:output_rfts_packing,kode_numbering|unique:output_rejects_packing,kode_numbering',
    ];

    protected $messages = [
        'sizeInput.required' => 'Harap scan qr.',
        'noCutInput.required' => 'Harap scan qr.',
        'numberingInput.required' => 'Harap scan qr.',
        'numberingInput.unique' => 'Kode qr sudah discan.',
    ];

    protected $listeners = [
        'submitRework' => 'submitRework',
        'submitAllRework' => 'submitAllRework',
        'cancelRework' => 'cancelRework',
        'hideDefectAreaImageClear' => 'hideDefectAreaImage',
        'updateWsDetailSizes' => 'updateWsDetailSizes',
        'setAndSubmitInputRework' => 'setAndSubmitInput',
        'toInputPanel' => 'resetError'
    ];

    public function dehydrate()
    {
        $this->resetValidation();
        $this->resetErrorBag();
    }

    public function resetError() {
        $this->resetValidation();
        $this->resetErrorBag();
    }

    public function updateWsDetailSizes($panel)
    {
        $this->orderInfo = session()->get('orderInfo', $this->orderInfo);
        $this->orderWsDetailSizes = session()->get('orderWsDetailSizes', $this->orderWsDetailSizes);

        $this->sizeInput = null;
        $this->sizeInputText = null;
        $this->noCutInput = null;
        $this->numberingInput = null;

        if ($panel == 'rework') {
            $this->emit('qrInputFocus', 'rework');
        }
    }

    public function updateOutput()
    {
        $this->output = DB::connection('mysql_sb')->table('output_defects_packing')->
            where('master_plan_id', $this->orderInfo->id)->
            where('defect_status', 'reworked')->
            count();

        $this->rework = DB::connection('mysql_sb')->table('output_defects_packing')->
            where('master_plan_id', $this->orderInfo->id)->
            where('defect_status', 'reworked')->
            whereRaw("DATE(updated_at) = '".date('Y-m-d')."'")->
            get();
    }

    public function loadReworkPage()
    {
        $this->emit('loadReworkPageJs');
    }

    public function mount(SessionManager $session, $orderWsDetailSizes)
    {
        $this->orderWsDetailSizes = $orderWsDetailSizes;
        $session->put('orderWsDetailSizes', $orderWsDetailSizes);

        $this->massSize = '';

        $this->info = true;

        $this->output = 0;
        $this->sizeInput = null;
        $this->sizeInputText = null;
        $this->noCutInput = null;
        $this->numberingInput = null;

        $this->rapidRework = [];
        $this->rapidReworkCount = 0;
    }

    public function closeInfo()
    {
        $this->info = false;
    }

    public function setDefectAreaPosition($x, $y)
    {
        $this->defectPositionX = $x;
        $this->defectPositionY = $y;
    }

    public function showDefectAreaImage($defectImage, $x, $y)
    {
        $this->defectImage = $defectImage;
        $this->defectPositionX = $x;
        $this->defectPositionY = $y;

        $this->emit('showDefectAreaImage', $this->defectImage, $this->defectPositionX, $this->defectPositionY);
    }

    public function hideDefectAreaImage()
    {
        $this->defectImage = null;
        $this->defectPositionX = null;
        $this->defectPositionY = null;
    }

    public function updatingSearchDefect()
    {
        $this->resetPage('defectsPage');
    }

    public function updatingSearchRework()
    {
        $this->resetPage('reworksPage');
    }

    public function submitAllRework() {
        $allDefect = DB::connection('mysql_sb')->table('output_defects_packing')->selectRaw('output_defects.id id, output_defects.master_plan_id master_plan_id, output_defects.kode_numbering, output_defects.no_cut_size, output_defects.so_det_id so_det_id')->
            leftJoin('so_det', 'so_det.id', '=', 'output_defects_packing.so_det_id')->
            where('output_defects_packing.defect_status', 'defect')->
            where('output_defects_packing.master_plan_id', $this->orderInfo->id)->get();

        if ($allDefect->count() > 0) {
            $rftArray = [];
            $rftArrayNds = [];
            foreach ($allDefect as $defect) {
                // create rework
                $createRework = ReworkModel::create([
                    "defect_id" => $defect->id,
                    "status" => "NORMAL",
                    'created_by' => Auth::user()->id
                ]);

                // add rft array
                array_push($rftArray, [
                    'master_plan_id' => $defect->master_plan_id,
                    'no_cut_size' => $defect->no_cut_size,
                    'kode_numbering' => $defect->kode_numbering,
                    'so_det_id' => $defect->so_det_id,
                    'status' => "REWORK",
                    'rework_id' => $createRework->id,
                    'created_by' => Auth::user()->username,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now()
                ]);

                // add rft array nds
                array_push($rftArrayNds, [
                    'sewing_line' => $this->orderInfo->sewing_line,
                    'master_plan_id' => $defect->master_plan_id,
                    'no_cut_size' => $defect->no_cut_size,
                    'kode_numbering' => $defect->kode_numbering,
                    'so_det_id' => $defect->so_det_id,
                    'status' => "REWORK",
                    'rework_id' => $createRework->id,
                    'created_by' => Auth::user()->username,
                    'created_at' => Carbon::now(),
                    'updated_at' => Carbon::now()
                ]);
            }
            // update defect
            $updateDefect = Defect::where('master_plan_id', $this->orderInfo->id)->update([
                "defect_status" => "reworked"
            ]);

            // create rft
            $createRft = Rft::insert($rftArray);

            // create rft nds
            $createRftNds = OutputPacking::insert($rftArrayNds);

            if ($allDefect->count() > 0) {
                $this->emit('alert', 'success', "Semua DEFECT berhasil di REWORK");
            } else {
                $this->emit('alert', 'error', "Terjadi kesalahan. DEFECT tidak berhasil di REWORK.");
            }
        } else {
            $this->emit('alert', 'warning', "Data tidak ditemukan.");
        }
    }

    public function preSubmitMassRework($defectType, $defectArea, $defectTypeName, $defectAreaName) {
        $this->massQty = 1;
        $this->massSize = '';
        $this->massDefectType = $defectType;
        $this->massDefectTypeName = $defectTypeName;
        $this->massDefectArea = $defectArea;
        $this->massDefectAreaName = $defectAreaName;

        $this->emit('showModal', 'massRework');
    }

    public function submitMassRework() {
        $selectedDefect = Defect::selectRaw('output_defects_packing.*, so_det.size as size')->
            leftJoin('so_det', 'so_det.id', '=', 'output_defects_packing.so_det_id')->
            where('output_defects_packing.defect_status', 'defect')->
            where('output_defects_packing.master_plan_id', $this->orderInfo->id)->
            where('output_defects_packing.defect_type_id', $this->massDefectType)->
            where('output_defects_packing.defect_area_id', $this->massDefectArea)->
            where('output_defects_packing.so_det_id', $this->massSize)->
            take($this->massQty)->get();

        if ($selectedDefect->count() > 0) {
            foreach ($selectedDefect as $defect) {
                // create rework
                $createRework = ReworkModel::create([
                    "defect_id" => $defect->id,
                    "status" => "NORMAL"
                ]);

                // update defect
                $defectSql = Defect::where('id', $defect->id)->update([
                    "defect_status" => "reworked"
                ]);

                // create rft
                $createRft = Rft::create([
                    'master_plan_id' => $defect->master_plan_id,
                    'no_cut_size' => $defect->no_cut_size,
                    'kode_numbering' => $defect->kode_numbering,
                    'so_det_id' => $defect->so_det_id,
                    'status' => 'REWORK',
                    'rework_id' => $createRework->id,
                    'created_by' => Auth::user()->username,
                ]);

                // create rft nds
                $createRftNds = OutputPacking::create([
                    'sewing_line' => $this->orderInfo->sewing_line,
                    'master_plan_id' => $defect->master_plan_id,
                    'no_cut_size' => $defect->no_cut_size,
                    'kode_numbering' => $defect->kode_numbering,
                    'so_det_id' => $defect->so_det_id,
                    'status' => 'REWORK',
                    'rework_id' => $createRework->id,
                    'created_by' => Auth::user()->username,
                ]);
            }

            if ($selectedDefect->count() > 0) {
                $this->emit('alert', 'success', "DEFECT dengan Ukuran : ".$selectedDefect[0]->size.", Tipe : ".$this->massDefectTypeName." dan Area : ".$this->massDefectAreaName." berhasil di REWORK sebanyak ".$selectedDefect->count()." kali.");

                $this->emit('hideModal', 'massRework');
            } else {
                $this->emit('alert', 'error', "Terjadi kesalahan. DEFECT dengan Ukuran : ".$selectedDefect[0]->size.", Tipe : ".$this->massDefectTypeName." dan Area : ".$this->massDefectAreaName." tidak berhasil di REWORK.");
            }
        } else {
            $this->emit('alert', 'warning', "Data tidak ditemukan.");
        }
    }

    public function submitRework($defectId) {
        $thisDefectRework = DB::connection('mysql_sb')->table('output_reworks_packing')->where('defect_id', $defectId)->count();

        if ($thisDefectRework < 1) {
            // add to rework
            $createRework = ReworkModel::create([
                "defect_id" => $defectId,
                "status" => "NORMAL"
            ]);

            // remove from defect
            $defect = Defect::where('id', $defectId)->first();
            $defect->defect_status = "reworked";
            $defect->save();

            // add to rft
            $createRft = Rft::create([
                'master_plan_id' => $defect->master_plan_id,
                'no_cut_size' => $defect->no_cut_size,
                'kode_numbering' => $defect->kode_numbering,
                'so_det_id' => $defect->so_det_id,
                "status" => "REWORK",
                "rework_id" => $createRework->id,
                'created_by' => Auth::user()->username,
            ]);

            // add to rft nds
            $createRftNds = OutputPacking::create([
                'sewing_line' => $this->orderInfo->sewing_line,
                'master_plan_id' => $defect->master_plan_id,
                'no_cut_size' => $defect->no_cut_size,
                'kode_numbering' => $defect->kode_numbering,
                'so_det_id' => $defect->so_det_id,
                "status" => "REWORK",
                "rework_id" => $createRework->id,
                'created_by' => Auth::user()->username,
            ]);

            if ($createRework && $createRft) {
                $this->emit('alert', 'success', "DEFECT dengan ID : ".$defectId." berhasil di REWORK.");
            } else {
                $this->emit('alert', 'error', "Terjadi kesalahan. DEFECT dengan ID : ".$defectId." tidak berhasil di REWORK.");
            }
        } else {
            $this->emit('alert', 'warning', "Pencegahan data redundant. DEFECT dengan ID : ".$defectId." sudah ada di REWORK.");
        }
    }

    public function cancelRework($reworkId, $defectId) {
        // delete from rework
        $deleteRework = ReworkModel::where('id', $reworkId)->delete();

        // add to defect
        $defect = Defect::where('id', $defectId)->first();
        $defect->defect_status = 'defect';
        $defect->save();

        // delete from rft
        $deleteRft = Rft::where('rework_id', $reworkId)->delete();

        // delete from rft nds
        $deleteRftNds = OutputPacking::where('rework_id', $reworkId)->delete();

        if ($deleteRework && $updateDefect && $deleteRft) {
            $this->emit('alert', 'success', "REWORK dengan REWORK ID : ".$reworkId." dan DEFECT ID : ".$defectId." berhasil di kembalikan ke DEFECT.");
        } else {
            $this->emit('alert', 'error', "Terjadi kesalahan. REWORK dengan REWORK ID : ".$reworkId." dan DEFECT ID : ".$defectId." tidak berhasil dikembalikan ke DEFECT.");
        }
    }

    public function submitInput()
    {
        $this->emit('renderQrScanner', 'rework');

        if ($this->numberingInput) {
            if (str_contains($this->numberingInput, 'WIP')) {
                $numberingData = DB::connection("mysql_nds")->table("stocker_numbering")->where("kode", $this->numberingInput)->first();
            } else {
                $numberingData = DB::connection("mysql_nds")->table("month_count")->selectRaw("month_count.*, month_count.id_month_year no_cut_size")->where("id_month_year", $this->numberingInput)->first();
            }

            if ($numberingData) {
                $this->sizeInput = $numberingData->so_det_id;
                $this->sizeInputText = $numberingData->size;
                $this->noCutInput = $numberingData->no_cut_size;
            }
        }

        $validatedData = $this->validate();

        $scannedDefectData = Defect::where("defect_status", "defect")->where("kode_numbering", $this->numberingInput)->first();

        if ($scannedDefectData && $this->orderWsDetailSizes->where('so_det_id', $this->sizeInput)->count() > 0) {
            // add to rework
            $createRework = ReworkModel::create([
                "defect_id" => $scannedDefectData->id,
                "status" => "NORMAL"
            ]);

            // remove from defect
            $scannedDefectData->defect_status = "reworked";
            $scannedDefectData->save();

            // add to rft
            $createRft = Rft::create([
                'master_plan_id' => $scannedDefectData->master_plan_id,
                'no_cut_size' => $scannedDefectData->no_cut_size,
                'kode_numbering' => $scannedDefectData->kode_numbering,
                'so_det_id' => $scannedDefectData->so_det_id,
                'status' => 'REWORK',
                'rework_id' => $createRework->id,
                'created_by' => Auth::user()->username
            ]);

            // add to rft nds
            $createRftNds = OutputPacking::create([
                'sewing_line' => $this->orderInfo->sewing_line,
                'master_plan_id' => $scannedDefectData->master_plan_id,
                'no_cut_size' => $scannedDefectData->no_cut_size,
                'kode_numbering' => $scannedDefectData->kode_numbering,
                'so_det_id' => $scannedDefectData->so_det_id,
                'status' => 'REWORK',
                'rework_id' => $createRework->id,
                'created_by' => Auth::user()->username
            ]);

            $this->sizeInput = '';
            $this->sizeInputText = '';
            $this->noCutInput = '';
            $this->numberingInput = '';

            if ($createRework && $createRft) {
                $this->emit('alert', 'success', "DEFECT dengan ID : ".$scannedDefectData->id." berhasil di REWORK.");
            } else {
                $this->emit('alert', 'error', "Terjadi kesalahan. DEFECT dengan ID : ".$scannedDefectData->id." tidak berhasil di REWORK.");
            }
        } else {
            $this->emit('alert', 'error', "Terjadi kesalahan. QR tidak sesuai.");
        }
    }

    public function setAndSubmitInput($scannedNumbering, $scannedSize, $scannedSizeText) {
        $this->numberingInput = $scannedNumbering;
        $this->sizeInput = $scannedSize;
        $this->sizeInputText = $scannedSizeText;

        $this->submitInput();
    }

    public function pushRapidRework($numberingInput, $sizeInput, $sizeInputText) {
        $exist = false;

        foreach ($this->rapidRework as $item) {
            if (($numberingInput && $item['numberingInput'] == $numberingInput)) {
                $exist = true;
            }
        }

        if (!$exist) {
            $this->rapidReworkCount += 1;

            if ($numberingInput) {
                array_push($this->rapidRework, [
                    'numberingInput' => $numberingInput,
                ]);
            }
        }
    }

    public function submitRapidInput() {
        $defectIds = [];
        $rftData = [];
        $rftDataNds = [];
        $success = 0;
        $fail = 0;

        if ($this->rapidRework && count($this->rapidRework) > 0) {
            for ($i = 0; $i < count($this->rapidRework); $i++) {
                $scannedDefectData = DB::connection('mysql_sb')->table('output_defects_packing')->where("defect_status", "defect")->where("kode_numbering", $this->rapidRework[$i]['numberingInput'])->first();

                if (($scannedDefectData) && ($this->orderWsDetailSizes->where('so_det_id', $scannedDefectData->so_det_id)->count() > 0)) {
                    $createRework = ReworkModel::create([
                        'defect_id' => $scannedDefectData->id,
                        'status' => 'NORMAL',
                        'created_by' => Auth::user()->username,
                    ]);

                    array_push($defectIds, $scannedDefectData->id);

                    array_push($rftData, [
                        'master_plan_id' => $this->orderInfo->id,
                        'so_det_id' => $scannedDefectData->so_det_id,
                        'no_cut_size' => $scannedDefectData->no_cut_size,
                        'kode_numbering' => $scannedDefectData->kode_numbering,
                        'rework_id' => $createRework->id,
                        'status' => 'REWORK',
                        'created_by' => Auth::user()->username,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now()
                    ]);

                    array_push($rftDataNds, [
                        'sewing_line' => $this->orderInfo->sewing_line,
                        'master_plan_id' => $this->orderInfo->id,
                        'so_det_id' => $scannedDefectData->so_det_id,
                        'no_cut_size' => $scannedDefectData->no_cut_size,
                        'kode_numbering' => $scannedDefectData->kode_numbering,
                        'rework_id' => $createRework->id,
                        'status' => 'REWORK',
                        'created_by' => Auth::user()->username,
                        'created_at' => Carbon::now(),
                        'updated_at' => Carbon::now()
                    ]);

                    $success += 1;
                } else {
                    $fail += 1;
                }
            }
        }

        // dd($rapidReworkFiltered, $defectIds, $rftData);

        $rapidDefectUpdate = Defect::whereIn('id', $defectIds)->update(["defect_status" => "reworked"]);
        $rapidRftInsert = Rft::insert($rftData);
        $rapidRftInsertNds = OutputPacking::insert($rftDataNds);

        $this->emit('alert', 'success', $success." output berhasil terekam. ");
        $this->emit('alert', 'error', $fail." output gagal terekam.");

        $this->rapidRework = [];
        $this->rapidReworkCount = 0;
    }

    public function render(SessionManager $session)
    {
        $this->emit('loadReworkPageJs');

        $this->orderInfo = $session->get('orderInfo', $this->orderInfo);
        $this->orderWsDetailSizes = $session->get('orderWsDetailSizes', $this->orderWsDetailSizes);

        $this->allDefectImage = MasterPlan::select('gambar')->find($this->orderInfo->id);

        $this->allDefectPosition = DB::connection('mysql_sb')->table('output_defects_packing')->where('output_defects_packing.defect_status', 'defect')->
            where('output_defects_packing.master_plan_id', $this->orderInfo->id)->
            get();

        $allDefectList =DB::connection('mysql_sb')->table('output_defects_packing')->selectRaw('output_defects_packing.defect_type_id, output_defects_packing.defect_area_id, output_defect_types.defect_type, output_defect_areas.defect_area, count(*) as total')->
            leftJoin('output_defect_areas', 'output_defect_areas.id', '=', 'output_defects_packing.defect_area_id')->
            leftJoin('output_defect_types', 'output_defect_types.id', '=', 'output_defects_packing.defect_type_id')->
            where('output_defects_packing.defect_status', 'defect')->
            where('output_defects_packing.master_plan_id', $this->orderInfo->id)->
            whereRaw("
                (
                    output_defect_types.defect_type LIKE '%".$this->allDefectListFilter."%' OR
                    output_defect_areas.defect_area LIKE '%".$this->allDefectListFilter."%'
                )
            ")->
            groupBy('output_defects_packing.defect_type_id', 'output_defects_packing.defect_area_id', 'output_defect_types.defect_type', 'output_defect_areas.defect_area')->
            orderBy('output_defects_packing.updated_at', 'desc')->
            paginate(5, ['*'], 'allDefectListPage');

        $defects = Defect::selectRaw('output_defects_packing.*, so_det.size as so_det_size')->
            leftJoin('so_det', 'so_det.id', '=', 'output_defects_packing.so_det_id')->
            leftJoin('output_defect_areas', 'output_defect_areas.id', '=', 'output_defects_packing.defect_area_id')->
            leftJoin('output_defect_types', 'output_defect_types.id', '=', 'output_defects_packing.defect_type_id')->
            where('output_defects_packing.defect_status', 'defect')->
            where('output_defects_packing.master_plan_id', $this->orderInfo->id)->
            whereRaw("(
                output_defects_packing.id LIKE '%".$this->searchDefect."%' OR
                so_det.size LIKE '%".$this->searchDefect."%' OR
                output_defect_areas.defect_area LIKE '%".$this->searchDefect."%' OR
                output_defect_types.defect_type LIKE '%".$this->searchDefect."%' OR
                output_defects_packing.defect_status LIKE '%".$this->searchDefect."%'
            )")->
            orderBy('output_defects_packing.updated_at', 'desc')->paginate(10, ['*'], 'defectsPage');

        $reworks = ReworkModel::selectRaw('output_reworks_packing.*, so_det.size as so_det_size')->
            leftJoin('output_defects', 'output_defects.id', '=', 'output_reworks_packing.defect_id')->
            leftJoin('output_defect_areas', 'output_defect_areas.id', '=', 'output_defects.defect_area_id')->
            leftJoin('output_defect_types', 'output_defect_types.id', '=', 'output_defects.defect_type_id')->
            leftJoin('so_det', 'so_det.id', '=', 'output_defects.so_det_id')->
            where('output_defects.defect_status', 'reworked')->
            where('output_defects.master_plan_id', $this->orderInfo->id)->
            whereRaw("(
                output_reworks_packing.id LIKE '%".$this->searchRework."%' OR
                output_defects.id LIKE '%".$this->searchRework."%' OR
                so_det.size LIKE '%".$this->searchRework."%' OR
                output_defect_areas.defect_area LIKE '%".$this->searchRework."%' OR
                output_defect_types.defect_type LIKE '%".$this->searchRework."%' OR
                output_defects.defect_status LIKE '%".$this->searchRework."%'
            )")->
            orderBy('output_reworks_packing.updated_at', 'desc')->paginate(10, ['*'], 'reworksPage');

        $this->massSelectedDefect = DB::connection('mysql_sb')->table('output_defects_packing')->selectRaw('output_defects_packing.so_det_id, so_det.size as size, count(*) as total')->
            leftJoin('so_det', 'so_det.id', '=', 'output_defects_packing.so_det_id')->
            where('output_defects_packing.defect_status', 'defect')->
            where('output_defects_packing.master_plan_id', $this->orderInfo->id)->
            where('output_defects_packing.defect_type_id', $this->massDefectType)->
            where('output_defects_packing.defect_area_id', $this->massDefectArea)->
            groupBy('output_defects_packing.so_det_id', 'so_det.size')->get();

        $this->output = DB::connection('mysql_sb')->table('output_defects_packing')->
            where('master_plan_id', $this->orderInfo->id)->
            where('defect_status', 'reworked')->
            count();

        $this->rework = DB::connection('mysql_sb')->table('output_defects_packing')->
            where('master_plan_id', $this->orderInfo->id)->
            where('defect_status', 'reworked')->
            whereRaw("DATE(updated_at) = '".date('Y-m-d')."'")->
            get();

        return view('livewire.rework' , ['defects' => $defects, 'reworks' => $reworks, 'allDefectList' => $allDefectList]);
    }
}
