@extends('layouts.admin')
@section('title',$definition['title'])
@section('content')
<div class="admin-heading"><div><h1>{{ $record->exists?'Bewerken':'Toevoegen' }} · {{ $definition['title'] }}</h1><p>{{ $record->exists?'Record #'.$record->id:'Nieuw record' }}</p></div><a class="btn btn-outline" href="{{ route('admin.list',['resource'=>$resource]) }}">Terug naar overzicht</a></div>
@if($resource==='inventory')<div class="admin-note">Serienummers, inkoopprijzen en interne notities zijn privé. Een gereserveerde of verkochte laptop kan alleen via de bestel- of retourworkflow worden gewijzigd. Testresultaten worden apart opgeslagen.</div>@endif
<form class="panel" method="post" action="{{ route($record->exists?'admin.update':'admin.store',array_filter(['resource'=>$resource,'id'=>$record->id])) }}">@csrf @if($record->exists)@method('PUT')@endif
<div class="admin-form-grid">@foreach($definition['fields'] as $field=>$details)
@php
[$label,$type]=$details;$value=$record->$field;
if(str_starts_with($field,'description_'))$value=$record->description[substr($field,12)]??'';
if(in_array($field,['seo_title','seo_description']))$value=$record->seo[substr($field,4)]??'';
if(is_array($value))$value=implode(',',$value);
if($type==='money')$value=number_format((int)$value/100,2,',','');
if($type==='datetime-local' && $value instanceof DateTimeInterface)$value=$value->format('Y-m-d\TH:i');
if($type==='date' && $value instanceof DateTimeInterface)$value=$value->format('Y-m-d');
$defaults=['supported_ram'=>'8,16,32','supported_ssd'=>'256,512,1024','operating_systems'=>'Windows 11 Pro,Linux,No OS','discount_percent'=>0,'processor_family'=>'i5','processor_generation'=>8,'ram_gb'=>8,'ssd_gb'=>256,'screen_inches'=>14,'resolution'=>'Full HD','graphics'=>'Intel UHD Graphics','keyboard_layout'=>'QWERTY','operating_system'=>'Windows 11 Pro','grade'=>'B','status'=>'awaiting_inspection','vat_treatment'=>'standard','quantity'=>0,'capacity'=>1,'type'=>$resource==='discounts'?'percent':'ram','value'=>10];
$value=old($field,$value??($defaults[$field]??''));
@endphp
<div @class(['form-field','full-width'=>$type==='textarea','checkbox-field'=>$type==='checkbox'])>
@if($type==='checkbox')<input type="hidden" name="{{ $field }}" value="0"><input id="{{ $field }}" name="{{ $field }}" type="checkbox" value="1" @checked($value)><label for="{{ $field }}">{{ $label }}</label>
@else<label for="{{ $field }}">{{ $label }}</label>
@if($type==='textarea')<textarea id="{{ $field }}" name="{{ $field }}" @if(str_contains($details[2],'required')) required @endif>{{ $value }}</textarea>
@elseif($type==='select' || $type==='product')<select id="{{ $field }}" name="{{ $field }}" @if(str_contains($details[2],'required')) required @endif>@if(str_contains($details[2],'nullable'))<option value="">Geen</option>@endif @foreach($type==='product'?$models:collect($details[3]) as $option)<option value="{{ $type==='product'?$option->id:$option }}" @selected((string)$value===(string)($type==='product'?$option->id:$option))>{{ $type==='product'?'#'.$option->id.' · '.$option->brand.' '.$option->name:$option }}</option>@endforeach</select>
@else<input id="{{ $field }}" name="{{ $field }}" type="{{ $type==='money'?'text':$type }}" value="{{ $value }}" @if($type==='number') step="any" @endif @if(str_contains($details[2],'required')) required @endif>@endif
@endif @error($field)<span class="help" style="color:#9b4127">{{ $message }}</span>@enderror</div>@endforeach</div><div class="form-actions"><button class="btn btn-primary">Opslaan <i data-lucide="check"></i></button></div></form>
@if($record->exists && in_array($resource,['products','inventory']))
<section class="panel admin-section">
    <h2>{{ $resource==='inventory'?'Foto’s van deze individuele laptop':'Modelafbeeldingen' }}</h2>
    <div class="photo-list">
        @forelse($record->images??[] as $photo)
        <figure class="admin-photo">
            <img src="{{ $photo }}" alt="Productfoto {{ $loop->iteration }}">
            <form method="post" action="{{ route('admin.image.destroy',['resource'=>$resource,'id'=>$record->id]) }}" data-confirm="Deze afbeelding verwijderen?">
                @csrf
                @method('DELETE')
                <input type="hidden" name="image" value="{{ $photo }}">
                <button class="btn btn-danger" type="submit" aria-label="Afbeelding {{ $loop->iteration }} verwijderen">Verwijderen</button>
            </form>
        </figure>
        @empty
        <p class="help">Nog geen afbeeldingen.</p>
        @endforelse
    </div>
    @error('image')<p class="help" role="alert">{{ $message }}</p>@enderror
    <form method="post" action="{{ route('admin.image',['resource'=>$resource,'id'=>$record->id]) }}" enctype="multipart/form-data">
        @csrf
        <div class="form-field"><label for="image">Foto toevoegen (JPG, PNG, WebP · maximaal 5 MB)</label><input id="image" type="file" name="image" accept="image/jpeg,image/png,image/webp" required></div>
        <button class="btn btn-outline">Uploaden</button>
    </form>
</section>
@endif
@if($record->exists && $resource==='inventory')<section class="panel admin-section"><h2>Testresultaten</h2><form method="post" action="{{ route('admin.inventory.tests',['id'=>$record->id]) }}">@csrf<div class="admin-form-grid">@foreach(['display','keyboard','trackpad','ram','ssd','battery','wifi','bluetooth','webcam','microphone','speakers','usb','video_outputs','charger','cooling','physical'] as $test)<div class="form-field"><label for="test-{{ $test }}">{{ ucfirst(str_replace('_',' ',$test)) }}</label><select id="test-{{ $test }}" name="tests[{{ $test }}]">@foreach(['not_tested'=>'Niet getest','pass'=>'Geslaagd','fail'=>'Afgekeurd'] as $result=>$label)<option value="{{ $result }}" @selected(($tests->get($test)?->result??'not_tested')===$result)>{{ $label }}</option>@endforeach</select></div>@endforeach</div><div class="form-field"><label for="notes">Toelichting</label><textarea id="notes" name="notes"></textarea></div><button class="btn btn-primary">Tests opslaan</button></form></section><section class="panel admin-section"><h2>Voorraadgeschiedenis</h2>@forelse($history as $event)<div class="summary-row"><span>{{ $event->from_status??'Nieuw' }} → {{ $event->to_status }}<br>{{ $event->note }}</span><time>{{ $event->created_at->format('d-m-Y H:i') }}</time></div>@empty<p>Nog geen wijzigingen.</p>@endforelse</section>@endif
@endsection
