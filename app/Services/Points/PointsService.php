<?php

namespace App\Services\Points;

use Illuminate\Http\Request;

interface PointsService
{
    public function getSummary(Request $request);
    public function getGifts(Request $request);
    public function redeemGift(Request $request, $gift_id);
    public function getHistory(Request $request);
}
