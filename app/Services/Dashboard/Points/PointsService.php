<?php

namespace App\Services\Dashboard\Points;

use Illuminate\Http\Request;

interface PointsService
{
    public function points(Request $request);
    public function addRule(Request $request);
    public function toggleRule(Request $request, $id);
    public function deleteRule(Request $request, $id);
    public function updateSettings(Request $request);
    public function resetAllPoints(Request $request);
    public function addGift(Request $request);
    public function toggleGift(Request $request, $id);
    public function deleteGift(Request $request, $id);
    public function updateRedemptionStatus(Request $request, $id);
}
