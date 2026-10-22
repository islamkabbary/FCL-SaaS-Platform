<?php

namespace App\Impersonation;

/**
 * Marks a model or Livewire component that an impersonated session may not reach at all,
 * not even to read it, until a support-access policy for it has been approved.
 *
 * RestrictionPolicy also treats every model in App\Models\Fcl and every FCL component and
 * route this way, so forgetting the marker on an FCL class does not open it.
 */
interface DeniedDuringImpersonation {}
