<?php

namespace App\Http\Livewire;

use Livewire\Component;
use App\Models\SignalBit\MasterPlan;
use App\Models\SignalBit\Rft;
use App\Models\SignalBit\Defect;
use App\Models\SignalBit\DefectType;
use App\Models\SignalBit\DefectArea;
use App\Models\SignalBit\Reject;
use App\Models\SignalBit\Rework;
use App\Models\SignalBit\Undo;
use App\Models\Nds\Numbering;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Carbon\Carbon;
use DB;

class ProductionPanelUniversal extends Component
{
    // Data
    public $orderDate;
    public $orderInfo;
    public $orderWsDetails;
    public $orderWsDetailSizes;
    public $outputRft;
    public $outputDefect;
    public $outputReject;
    public $outputRework;
    public $outputFiltered;

    // Filter
    public $selectedColor;
    public $selectedColorName;
    public $selectedSize;

    // Panel views
    public $panels;
    public $rft;
    public $defect;
    public $defectHistory;
    public $reject;
    public $rework;

    // Undo
    public $undoSizes;
    public $undoType;
    public $undoQty;
    public $undoSize;
    public $undoDefectType;
    public $undoDefectArea;

    // Input
    public $scannedNumberingCode;
    public $scannedNumberingInput;
    public $scannedSizeInput;
    public $scannedSizeInputText;

    // Rules
    protected $rules = [
        'undoType' => 'required',
        'undoQty' => 'required|numeric|min:1',
        'undoSize' => 'required',
    ];

    protected $messages = [
        'undoType.required' => 'Terjadi kesalahan, tipe undo output tidak terbaca.',
        'undoQty.required' => 'Harap tentukan kuantitas undo output.',
        'undoQty.numeric' => 'Harap isi kuantitas undo output dengan angka.',
        'undoQty.min' => 'Kuantitas undo output tidak bisa kurang dari 1.',
        'undoSize.required' => 'Harap tentukan ukuran undo output.',
    ];

    // Event listeners
    protected $listeners = [
        'toProductionPanel' => 'toProductionPanel',
        'toRft' => 'toRft',
        'toDefect' => 'toDefect',
        'toDefectHistory' => 'toDefectHistory',
        'toReject' => 'toReject',
        'toRework' => 'toRework',
        'countRft' => 'countRft',
        'countDefect' => 'countDefect',
        'countReject' => 'countReject',
        'countRework' => 'countRework',
        'preSubmitUndo' => 'preSubmitUndo',
        'updateOrder' => 'updateOrder',
    ];

    public function mount(SessionManager $session)
    {
        // Default value
        $this->orderDate = date('Y-m-d');
        $this->panels = true;
        $this->rft = false;
        $this->defect = false;
        $this->defectHistory = false;
        $this->reject = false;
        $this->rework = false;

        $this->orderWsDetailSizes = DB::table('master_plan')->selectRaw("
                master_plan.id as master_plan_id,
                MIN(act_costing.kpno) as ws,
                so_det.color as color,
                so_det.id as so_det_id,
                so_det.size as size,
                so_det.dest as dest,
                CONCAT(so_det.size, (CASE WHEN so_det.dest != '-' OR so_det.dest IS NULL THEN CONCAT('-', so_det.dest) ELSE '' END)) as size_dest
            ")
            ->leftJoin('act_costing', 'act_costing.id', '=', 'master_plan.id_ws')
            ->leftJoin('so', 'so.id_cost', '=', 'act_costing.id')
            ->leftJoin('so_det', function($join) {
                $join->on('so_det.id_so', '=', 'so.id');
                $join->on('so_det.color', '=', 'master_plan.color');
            })
            ->leftJoin('mastersupplier', 'mastersupplier.id_supplier', '=', 'act_costing.id_buyer')
            ->where('so_det.cancel', '!=', 'Y')
            ->where('master_plan.cancel', '!=', 'Y')
            ->where('master_plan.tgl_plan', $this->orderDate)
            ->where('master_plan.sewing_line', Auth::user()->username)
            ->groupBy('master_plan.id', 'so_det.color', 'so_det.size', 'so_det.dest', 'so_det.id')
            ->orderBy('so_det_id')
            ->get();

        $session->put("orderWsDetailSizes", $this->orderWsDetailSizes);
    }

    public function toRft()
    {
        $this->panels = false;
        $this->rft = !($this->rft);
        $this->emit('toInputPanel', 'rft');
    }

    public function toDefect()
    {
        $this->panels = false;
        $this->defect = !($this->defect);
        $this->emit('toInputPanel', 'defect');
    }

    public function toDefectHistory()
    {
        $this->panels = false;
        $this->defectHistory = !($this->defectHistory);
        $this->emit('toInputPanel', 'defect-history');
    }

    public function toReject()
    {
        $this->panels = false;
        $this->reject = !($this->reject);
        $this->emit('toInputPanel', 'reject');
    }

    public function toRework()
    {
        $this->panels = false;
        $this->rework = !($this->rework);
        $this->emit('toInputPanel', 'rework');
    }

    public function toProductionPanel()
    {
        $this->emit('fromInputPanel');
        $this->panels = true;
        $this->rft = false;
        $this->defect = false;
        $this->defectHistory = false;
        $this->reject = false;
        $this->rework = false;
    }

    public function preSubmitUndo($undoType)
    {
        $this->undoQty = 1;
        $this->undoSize = '';
        $this->undoType = $undoType;

        $this->emit('showModal', 'undo');
    }

    public function setAndSubmitInput($type) {
        $this->emit('loadingStart');

        if ($this->scannedNumberingCode) {
            $numberingData = Numbering::where("kode", $this->scannedNumberingCode)->first();
        }

        if ($type == "rft") {
            $this->toRft();
            $this->emit('setAndSubmitInputRft', $this->scannedNumberingCode);
        }

        if ($type == "defect") {
            $this->toDefect();
            $this->emit('setAndSubmitInputDefect', $this->scannedNumberingInput);
        }

        if ($type == "reject") {
            $this->toReject();
            $this->emit('setAndSubmitInputReject', $this->scannedNumberingInput);
        }

        if ($type == "rework") {
            $this->toRework();
            $this->emit('setAndSubmitInputRework', $this->scannedNumberingInput);
        }
    }

    public function render(SessionManager $session)
    {
        // Keep this data with session
        $this->orderWsDetailSizes = $session->get("orderWsDetailSizes", $this->orderWsDetailSizes);

        $this->outputRft = Rft::
            leftJoin('master_plan', 'master_plan.id', '=', 'output_rfts_packing.master_plan_id')->
            where('master_plan.sewing_line', Auth::user()->username)->
            where('master_plan.tgl_plan', $this->orderDate)->
            where('status', 'NORMAL')->
            count();
        $this->outputDefect = Defect::
            leftJoin('master_plan', 'master_plan.id', '=', 'output_defects_packing.master_plan_id')->
            where('master_plan.sewing_line', Auth::user()->username)->
            where('master_plan.tgl_plan', $this->orderDate)->
            where('defect_status', 'defect')->
            count();
        $this->outputReject = Reject::
            leftJoin('master_plan', 'master_plan.id', '=', 'output_rejects_packing.master_plan_id')->
            where('master_plan.sewing_line', Auth::user()->username)->
            where('master_plan.tgl_plan', $this->orderDate)->
            count();
        $this->outputRework = Defect::
            leftJoin('master_plan', 'master_plan.id', '=', 'output_defects_packing.master_plan_id')->
            where('master_plan.sewing_line', Auth::user()->username)->
            where('master_plan.tgl_plan', $this->orderDate)->
            where('defect_status', 'reworked')->
            count();

        return view('livewire.production-panel-universal');
    }

    public function dehydrate()
    {
        $this->resetValidation();
        $this->resetErrorBag();
    }
}
