<?php

namespace App\Services\M;

class PurchaseOrderTask extends \App\Services\BaseService
{

    protected ?string $connection = 'mysql_master';

    protected string $table = 'purchaseordertesk';

    public function getByIdAS($id): ?object
    {
        return $this->mtb->where('Purchaseordertesk_id', $id)->first();
    }
}
