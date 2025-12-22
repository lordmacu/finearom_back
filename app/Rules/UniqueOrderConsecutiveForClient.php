<?php
namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use App\Models\PurchaseOrder;

class UniqueOrderConsecutiveForClient implements ValidationRule
{
    protected $clientId;
    protected $excludeId;

    public function __construct($clientId, $excludeId = null)
    {
        $this->clientId = $clientId;
        $this->excludeId = $excludeId;
    }

    /**
     * Run the validation rule.
     *
     * @param  \Closure(string): \Illuminate\Translation\PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = PurchaseOrder::where('client_id', $this->clientId)
                              ->where('order_consecutive', $value);

        if ($this->excludeId) {
            $query->where('id', '!=', $this->excludeId);
        }

        if ($query->exists()) {
            $fail('The order consecutive has already been taken for this client.');
        }
    }
}
