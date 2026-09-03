<?php

namespace App\Http\Controllers\Shop;

use App\Exceptions\Shop\CustomerDeletedException;
use App\Exceptions\Shop\InvalidMagicLinkException;
use App\Jobs\Shop\AttachExistingOrdersToCustomer;
use App\Services\Shop\CustomerAuthService;
use App\Support\Shop\SafeReturnPath;
use Illuminate\Http\Request;

class CustomerAuthController
{
    public function __construct(protected CustomerAuthService $auth) {}

    public function loginForm(Request $request)
    {
        return view('shop.account.login', [
            'site' => $request->attributes->get('resolved_site'),
            'return' => SafeReturnPath::shop($request->query('return')),
        ]);
    }

    public function requestLink(Request $request)
    {
        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);

        $data = $request->validate([
            'email' => 'required|email',
            'password' => 'nullable|string',
        ]);

        $return = SafeReturnPath::shop($request->input('return'));
        if ($return) {
            $request->session()->put('shop.auth.return', $return);
        }

        if (! empty($data['password'])) {
            $customer = $this->auth->loginWithPassword($site->id, $data['email'], $data['password']);
            if ($customer) {
                auth('customer')->login($customer);

                // Any proven identity attaches historic guest orders (checkout no longer links by posted email).
                AttachExistingOrdersToCustomer::dispatch($customer->id);

                return redirect($this->consumeReturn($request) ?? '/shop/account');
            }
        }

        try {
            $this->auth->requestLinkFor($site->id, $data['email'], $request->ip());
        } catch (CustomerDeletedException) {
            // Keep the rendered response neutral. Timing and whether mail arrives are
            // still distinguishers; this only closes the flashed-message oracle.
        }

        return back()->with('status', 'Check your inbox for a sign-in link if that email can be used.');
    }

    public function verify(Request $request)
    {
        $site = $request->attributes->get('resolved_site');
        abort_unless($site, 404);

        $token = (string) $request->query('token', '');
        try {
            $customer = $this->auth->consumeLink($site->id, $token, $request->ip());
            auth('customer')->login($customer);

            // Retrofit-attach any historic orders for this (site_id, email)
            AttachExistingOrdersToCustomer::dispatch($customer->id);

            $return = SafeReturnPath::shop($request->query('return'))
                ?? $this->consumeReturn($request);

            return redirect($return ?? '/shop/account');
        } catch (InvalidMagicLinkException|CustomerDeletedException $e) {
            return view('shop.account.link-invalid', ['site' => $site]);
        }
    }

    private function consumeReturn(Request $request): ?string
    {
        return SafeReturnPath::shop($request->session()->pull('shop.auth.return'));
    }

    public function logout(Request $request)
    {
        auth('customer')->logout();

        return redirect('/shop');
    }
}
