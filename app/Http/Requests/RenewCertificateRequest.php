<?php

namespace App\Http\Requests;

class RenewCertificateRequest extends StoreCertificateRequest
{
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['vessel_id'], $rules['certificate_type_id'], $rules['remarks']);

        return $rules;
    }
}
