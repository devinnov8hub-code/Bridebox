@extends('admin.layout')

@section('title', __('Edit Subject'))

@section('main')
    <main class="main">
        <header class="topbar">
            <div class="greeting">
                <p class="eyebrow">{{ __('Admin') }}</p>
                <h1>{{ __('Edit Subject') }}</h1>
                <p class="subtext">{{ __('Update subject details for a section.') }}</p>
            </div>
            <div class="actions">
                <a class="btn ghost" href="{{ route('admin.subjects.index') }}">{{ __('Back to Subjects') }}</a>
                <form action="{{ route('logout') }}" method="post">
                    @csrf
                    <button class="btn primary" type="submit">{{ __('Logout') }}</button>
                </form>
            </div>
        </header>

        <section class="panel">
            <div class="panel-header">
                <h4>{{ __('Subject Details') }}</h4>
                <span class="badge gold">{{ __('Required') }}</span>
            </div>
            <div class="panel-body">
                <form class="form-grid" action="{{ route('admin.subjects.update', $subject) }}" method="post">
                    @csrf
                    @method('put')
                    <div class="form-field">
                        <label for="name">{{ __('Name') }}</label>
                        <input id="name" name="name" type="text" value="{{ old('name', $subject->name) }}" required>
                        @error('name')
                            <span class="form-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-field">
                        <label for="section_id">{{ __('Section') }}</label>
                        <select id="section_id" name="section_id" required>
                            <option value="" disabled @selected(!old('section_id', $subject->section_id))>{{ __('Select a section') }}</option>
                            @foreach ($sections as $section)
                                <option value="{{ $section->id }}" @selected(old('section_id', $subject->section_id) == $section->id)>{{ $section->name }}</option>
                            @endforeach
                        </select>
                        @error('section_id')
                            <span class="form-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-field form-field-full">
                        <label for="description">{{ __('Description (optional)') }}</label>
                        <textarea id="description" name="description">{{ old('description', $subject->description) }}</textarea>
                        @error('description')
                            <span class="form-error">{{ $message }}</span>
                        @enderror
                    </div>

                    <div class="form-actions">
                        <button class="btn primary" type="submit">{{ __('Save Changes') }}</button>
                    </div>
                </form>
            </div>
        </section>
    </main>
@endsection
