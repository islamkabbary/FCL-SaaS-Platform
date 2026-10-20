<?php

namespace App\Http\Middleware;

use Stancl\Tenancy\Exceptions\NotASubdomainException;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;

class InitializeTenancyByStrictSubdomain extends InitializeTenancyBySubdomain
{
    /**
     * Identify the tenant only from a host that is exactly "{subdomain}.{central domain}".
     *
     * The package checks the host with a plain string suffix (no dot boundary) and takes the
     * first label, so "acme.evilfcl.app" or "acme.other.fcl.app" would resolve the tenant
     * "acme". Links built from the request host (password reset e-mails) would then point to
     * a domain the attacker controls. Anything other than one label directly under a central
     * domain is refused, which the identification failure handler turns into a 404.
     *
     * @return string|NotASubdomainException
     */
    protected function makeSubdomain(string $hostname)
    {
        $hostname = strtolower($hostname);

        foreach (config('tenancy.central_domains') as $central) {
            $suffix = '.'.strtolower($central);

            if (! str_ends_with($hostname, $suffix)) {
                continue;
            }

            $subdomain = substr($hostname, 0, -strlen($suffix));

            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $subdomain) === 1) {
                return $subdomain;
            }
        }

        return new NotASubdomainException($hostname);
    }
}
