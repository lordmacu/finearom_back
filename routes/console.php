<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('debug:mailer', function () {
    try {
        $order = \App\Models\PurchaseOrder::first();
        if (!$order) {
            $this->error('No order found'); return;
        }
        $ccEmails = ['test@test.com'];
        // pass explicit nulls to see if it breaks
        $data = [
             'order_id' => $order->id,
             'order_consecutive' => $order->order_consecutive,
             'old_status' => null, // try null
             'new_status' => $order->status,
             'cc_emails' => $ccEmails
        ];
        
        $mailable = new \App\Mail\PurchaseOrderStatusChangedMail($order, 'status_change', $data);
        
        // Use 'log' mailer to trigger real message building
        $mailer = \Mail::mailer('log'); 
        $mailer->to('test@example.com')->send($mailable);
        
        $this->info('Sent successfully using log driver');
    } catch (\Throwable $e) {
        $this->error($e->getMessage());
        $this->error($e->getFile() . ':' . $e->getLine());
        $this->error($e->getTraceAsString());
    }
});
