<?php

namespace App\Http\Controllers\Admin;

use App\Models\Lesson;
use App\Models\Topic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use App\Http\Controllers\Controller;

class AdminTopicLessonController extends Controller
{
    public function index(Topic $topic): View
    {
        $lessons = $topic->lessons()
            ->orderBy('created_at')
            ->paginate(10)
            ->withQueryString();

        return view('admin.topics.lessons.index', [
            'topic' => $topic,
            'lessons' => $lessons,
        ]);
    }

    public function create(Topic $topic): View
    {
        return view('admin.topics.lessons.create', [
            'topic' => $topic,
        ]);
    }

    public function store(Request $request, Topic $topic): RedirectResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:191',
            'content' => 'nullable|string',
            // allow common video formats, increase max to 200MB (204800 KB)
            'file' => 'nullable|file|max:204800|mimes:pdf,mp4,webm,ogg,mov,mkv',
        ]);

        $content = trim((string) ($data['content'] ?? ''));
        $file = $request->file('file');

        if ($content === '' && !$file) {
            return back()
                ->withErrors(['content' => 'Provide lesson text or upload a file.'])
                ->withInput();
        }

        $filePath = null;
        $fileName = null;
        $fileType = null;

        if ($file) {
            $originalName = $file->getClientOriginalName();
            $extension = $file->getClientOriginalExtension();
            $storedName = Str::uuid()->toString();
            if ($extension !== '') {
                $storedName .= '.' . $extension;
            }

            $filePath = $file->storeAs('lessons', $storedName, 'local');
            $fileName = $originalName;
            $fileType = $file->getClientMimeType();
        }

        $lesson = $topic->lessons()->create([
            'title' => $data['title'],
            'content' => $content !== '' ? $content : null,
            'file_path' => $filePath,
            'file_name' => $fileName,
            'file_type' => $fileType,
        ]);

        // If a file was uploaded, rename the stored file to a more readable name
        if ($filePath && $fileName) {
            $extension = pathinfo($filePath, PATHINFO_EXTENSION);
            $slug = Str::slug(substr($data['title'], 0, 120));
            $newName = ($slug ?: 'lesson') . '-' . $lesson->id;
            if ($extension !== '') {
                $newName .= '.' . $extension;
            }
            $newPath = 'lessons/' . $newName;

            try {
                if (Storage::disk('local')->exists($filePath)) {
                    Storage::disk('local')->move($filePath, $newPath);
                    $lesson->update([
                        'file_path' => $newPath,
                        'file_name' => $fileName,
                    ]);
                }
            } catch (\Exception $e) {
                // If rename fails, keep original file path; don't interrupt the flow
            }
        }

        return redirect()
            ->route('admin.topics.lessons.index', $topic)
            ->with([
                'status' => 'success',
                'message' => 'Lesson added to topic.',
            ]);
    }

    public function download(Topic $topic, Lesson $lesson)
    {
        $this->assertLessonTopic($topic, $lesson);

        if (!$lesson->file_path || !Storage::disk('local')->exists($lesson->file_path)) {
            abort(404);
        }

        $downloadName = $lesson->file_name ?: basename($lesson->file_path);

        return Storage::disk('local')->download($lesson->file_path, $downloadName);
    }

    public function file(Topic $topic, Lesson $lesson)
    {
        $this->assertLessonTopic($topic, $lesson);

        if (!$lesson->file_path || !Storage::disk('local')->exists($lesson->file_path)) {
            abort(404);
        }

        $path = Storage::disk('local')->path($lesson->file_path);
        $filename = $lesson->file_name ?: basename($lesson->file_path);
        $mime = $lesson->file_type ?: null;

        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
        ]);
    }

    public function show(Topic $topic, Lesson $lesson)
    {
        $this->assertLessonTopic($topic, $lesson);

        return view('admin.topics.lessons.show', [
            'topic' => $topic,
            'lesson' => $lesson->load(['topic.subject', 'topic.schoolClass']),
        ]);
    }

    public function destroy(Topic $topic, Lesson $lesson): RedirectResponse
    {
        $this->assertLessonTopic($topic, $lesson);

        if ($lesson->file_path && Storage::disk('local')->exists($lesson->file_path)) {
            Storage::disk('local')->delete($lesson->file_path);
        }

        $lesson->delete();

        return back()->with([
            'status' => 'success',
            'message' => 'Lesson deleted.',
        ]);
    }

    public function byTopic(Request $request): JsonResponse
    {
        $topicId = $request->integer('topic_id');

        if (!$topicId) {
            return response()->json([]);
        }

        $lessons = Lesson::query()
            ->where('topic_id', $topicId)
            ->orderBy('title')
            ->get(['id', 'title']);

        return response()->json($lessons);
    }

    private function assertLessonTopic(Topic $topic, Lesson $lesson): void
    {
        if ($lesson->topic_id !== $topic->id) {
            abort(404);
        }
    }
}
