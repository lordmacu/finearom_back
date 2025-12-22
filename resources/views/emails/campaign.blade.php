@extends('emails.layout')

@section('title', 'Campaña - Finearom')
@section('email_title', 'Campaña Finearom')

@section('content')
    <div>
        {!! $body !!}
    </div>

    @if(!empty($logId))
        <img
            src="{{ url('/api/email-campaigns/track-open/' . $logId) }}"
            width="1"
            height="1"
            style="display:none;"
            alt=""
        />
    @endif
@endsection

@section('signature')
    <div>
        <p><strong>EQUIPO FINEAROM</strong></p>
    </div>
@endsection
