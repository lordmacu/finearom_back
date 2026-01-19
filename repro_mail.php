<?php

try {
    echo "Starting reproduction...\n";

    $order = \App\Models\PurchaseOrder::first();
    if (!$order) {
        // Create a dummy order if needed, or fail
        echo "No PurchaseOrder found. Cannot reproduce.\n";
        return;
    }

    echo "Found Order: " . $order->id . "\n";

    $ccEmails = ['test+cc@example.com'];
    $data = [
        'order_id' => $order->id,
        'order_consecutive' => $order->order_consecutive,
        'old_status' => '',
        'new_status' => $order->status,
        'cc_emails' => $ccEmails,
    ];

    echo "Creating Mailable...\n";
    $mailable = new \App\Mail\PurchaseOrderStatusChangedMail($order, 'status_change', $data);

    // Inspect if 'metadata' property exists and is populated
    if (property_exists($mailable, 'metadata')) {
        echo "Property 'metadata' exists.\n";
        var_dump($mailable->metadata);
    } else {
        echo "Property 'metadata' does NOT exist.\n";
    }

    \Illuminate\Support\Facades\Mail::fake();
    
    echo "Sending Mail...\n";
    \Illuminate\Support\Facades\Mail::to('recipient@example.com')->send($mailable);

    echo "Mail sent successfully (Mocked).\n";

} catch (\Throwable $e) {
    echo "Caught Exception: " . get_class($e) . "\n";
    echo "Message: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo $e->getTraceAsString();
}
