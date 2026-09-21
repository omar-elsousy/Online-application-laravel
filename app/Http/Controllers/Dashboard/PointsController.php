<?php

namespace App\Http\Controllers\Dashboard;

use App\Http\Controllers\Controller;
use App\Services\Dashboard\Points\PointsService;
use Illuminate\Http\Request;

class PointsController extends Controller
{
    protected $pointsService;

    public function __construct(PointsService $pointsService)
    {
        $this->pointsService = $pointsService;
    }

    public function points(Request $request)
    {
        return $this->pointsService->points($request);
    }

    public function addRule(Request $request)
    {
        return $this->pointsService->addRule($request);
    }

    public function toggleRule(Request $request, $id)
    {
        return $this->pointsService->toggleRule($request, $id);
    }

    public function deleteRule(Request $request, $id)
    {
        return $this->pointsService->deleteRule($request, $id);
    }

    public function updateSettings(Request $request)
    {
        return $this->pointsService->updateSettings($request);
    }

    public function resetAllPoints(Request $request)
    {
        return $this->pointsService->resetAllPoints($request);
    }

    public function addGift(Request $request)
    {
        return $this->pointsService->addGift($request);
    }

    public function toggleGift(Request $request, $id)
    {
        return $this->pointsService->toggleGift($request, $id);
    }

    public function deleteGift(Request $request, $id)
    {
        return $this->pointsService->deleteGift($request, $id);
    }

    public function updateRedemptionStatus(Request $request, $id)
    {
        return $this->pointsService->updateRedemptionStatus($request, $id);
    }
}
