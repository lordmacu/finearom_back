<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Storage;
use App\Models\PurchaseOrder;
use Log;
use Symfony\Component\Mime\Header\TagHeader;

class EmailSentListener
{
    /**
     * Handle the event.
     *
     * @param  \Illuminate\Mail\Events\MessageSent  $event
     * @return void
     */
    public function handle(MessageSent $event)
    {
        // Obtén el message_id del mensaje enviado
        $messageId = $event->sent->getMessageId();
        $headers = $event->message->getHeaders();

        $tagHeader = $headers->get('x-tag');

        if ($tagHeader) {
            $purchaseOrderId = $tagHeader->getValue();
            $data=json_decode($purchaseOrderId, true);

            $headers = $event->message->getHeaders();

            $purchaseOrder = PurchaseOrder::find($data["purchase"]);

            if ($purchaseOrder) {
                if ($data["type"] == "order") {
                    $purchaseOrder->message_id = $messageId;
                }
                if ($data["type"] == "despacho") {
                    $purchaseOrder->message_despacho_id = $messageId;
                }

                $purchaseOrder->save();
            }
        }
    }
}
