@php $publisher = \App\Support\PublicationInfo::publisher(); @endphp
<h2>Publisher &amp; editorial responsibility</h2>
@if($publisher['name'])<p>Publisher: {{ $publisher['name'] }}</p>@endif
@if($publisher['editor'])<p>Responsible editor: {{ $publisher['editor'] }}</p>@endif
@if($publisher['contact'])<p>Publisher contact: {{ $publisher['contact'] }}</p>@endif
@if(! $publisher['name'] || ! $publisher['editor'])
  <p>The responsible publisher and editor details are awaiting confirmation. Editorial questions can be sent through our <a href="{{ route('page', 'contact') }}">contact page</a>.</p>
@endif
