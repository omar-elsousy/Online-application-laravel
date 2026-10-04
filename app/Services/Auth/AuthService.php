<?php
namespace App\Services\Auth;
use Illuminate\Http\Request;

interface AuthService
{
    public function registrationCustomers(Request $request);
    public function register(Request $request);
    public function login(Request $request);
    public function logout(Request $request);
    public function changePassword(Request $request);
    public function sendOtp(Request $request);
    public function resetPassword(Request $request);
}
