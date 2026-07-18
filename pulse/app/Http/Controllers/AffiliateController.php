<?php

namespace App\Http\Controllers;

use App\Models\Affiliate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AffiliateController extends Controller
{
    /** Public "Become an affiliate" application form. */
    public function apply()
    {
        return view('affiliate.apply');
    }

    public function submitApplication(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:180', 'unique:affiliates,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'website' => ['nullable', 'string', 'max:300'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'terms' => ['accepted'],
        ], [
            'terms.accepted' => 'You must accept the affiliate terms.',
        ]);

        Affiliate::create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'website' => $data['website'] ?? null,
            'notes' => $data['notes'] ?? null,
            'status' => 'pending',
        ]);

        return redirect()->route('affiliate.login')
            ->with('status', 'Application received! We review every application — you can sign in once you are approved.');
    }

    public function showLogin()
    {
        if (Auth::guard('affiliate')->check()) {
            return redirect()->route('affiliate.dashboard');
        }

        return view('affiliate.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::guard('affiliate')->attempt($credentials, remember: true)) {
            throw ValidationException::withMessages(['email' => 'Invalid email or password.']);
        }

        /** @var Affiliate $affiliate */
        $affiliate = Auth::guard('affiliate')->user();

        if ($affiliate->status !== 'active') {
            Auth::guard('affiliate')->logout();
            $message = $affiliate->status === 'pending'
                ? 'Your application is still under review.'
                : 'This affiliate account is suspended.';
            throw ValidationException::withMessages(['email' => $message]);
        }

        $request->session()->regenerate();

        return redirect()->route('affiliate.dashboard');
    }

    public function logout(Request $request)
    {
        Auth::guard('affiliate')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('affiliate.login');
    }

    /** The affiliate's stats dashboard. */
    public function dashboard()
    {
        /** @var Affiliate $affiliate */
        $affiliate = Auth::guard('affiliate')->user();

        $clicks30 = $affiliate->clicks()
            ->where('is_valid', true)
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        return view('affiliate.dashboard', [
            'affiliate' => $affiliate,
            'validClicks' => $affiliate->validClicksCount(),
            'clicks30' => $clicks30,
            'clickEarnings' => $affiliate->clickEarnings(),
            'saleEarnings' => $affiliate->saleEarnings(),
            'totalEarned' => $affiliate->totalEarned(),
            'totalPaid' => $affiliate->totalPaid(),
            'balance' => $affiliate->balance(),
            'conversions' => $affiliate->conversions()->latest()->take(10)->get(),
            'payouts' => $affiliate->payouts()->latest('paid_at')->take(10)->get(),
        ]);
    }
}
