@extends('layouts.admin')
@section('title', 'Marketplaces')
@push('styles')
@vite(['resources/css/relay.css', 'resources/js/relay.js'])
@endpush
@section('content')
<div class="revilo-relay">
  <div id="workspace" class="workspace"><div class="main-shell">
    <header class="native-relay-top"><nav class="native-relay-tabs" aria-label="Marketplacebeheer"><button data-view="inventory" class="active">Voorraad <b id="nav-count">0</b></button><button data-view="channels">Koppelingen</button><button data-view="activity">Acties <b id="job-count"></b></button><button data-view="guide">Aan de slag</button></nav><span id="storage">Revilo-catalogus</span><span id="breadcrumb" class="hidden">Voorraad</span></header>
    <main id="main"></main>
  </div></div>
  <dialog id="editor" class="editor"></dialog><dialog id="connection" class="connection"></dialog><div id="toast" role="status" aria-live="polite"></div><div id="loading" class="initial-loading">revilo<span>Marketplaces laden…</span></div>
</div>
@endsection
