<?php

namespace App\Http\Requests;

use App\Models\Topic;
use App\Models\Unit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCourseMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Let validation provide the specific error messages.
        return auth()->check();
    }

    public function rules(): array
    {
        return [
            // The subject is derived from the selected unit's teaching area.
            // Older clients may still send subject_id; when present, validate it.
            'subject_id' => [
                'nullable',
                'integer',
                'exists:subjects,id',
            ],

            'unit_id' => [
                'required',
                'integer',
                'exists:units,id',
            ],

            'scope' => [
                'required',
                'in:unit,topic',
            ],

            'title' => [
                'required',
                'string',
                'max:255',
            ],

            'description' => [
                'nullable',
                'string',
            ],

            'topic_id' => [
                'nullable',
                'integer',
                'exists:topics,id',
            ],

            'material_file' => [
                'required',
                'file',
                'mimes:pdf',
                'max:51200',
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {

            $user = $this->user();

            if (!$user) {
                return;
            }

            $unit = Unit::with('gradeSubject')
                ->find($this->input('unit_id'));

            if (!$unit) {
                return;
            }

            $gradeSubject = $unit->gradeSubject;

            if (!$gradeSubject) {
                $validator->errors()->add(
                    'unit_id',
                    'The selected unit does not belong to a valid teaching area.'
                );

                return;
            }

            /*
             * TEMPORARY TEACHING-AREA ACCESS
             *
             * Legacy grade_subjects may have:
             *   teacher_id = NULL
             *   school_id  = NULL
             *
             * Therefore we do NOT require teacher_id ownership yet.
             *
             * A teacher can work with a teaching area if:
             * - it belongs to their school, OR
             * - it is a legacy teaching area with school_id = NULL.
             *
             * Admin can access everything.
             */
            if ($user->role !== 'admin') {

                $sameSchool =
                    $gradeSubject->school_id !== null &&
                    (int) $gradeSubject->school_id === (int) $user->school_id;

                $legacyTeachingArea =
                    $gradeSubject->school_id === null;

                if (!$sameSchool && !$legacyTeachingArea) {
                    $validator->errors()->add(
                        'unit_id',
                        'You are not authorized to add material to this unit.'
                    );

                    return;
                }
            }

            /*
             * Make sure the selected subject matches
             * the subject of the teaching area.
             */
            if ($this->filled('subject_id')) {

                if (
                    (int) $gradeSubject->subject_id !==
                    (int) $this->input('subject_id')
                ) {
                    $validator->errors()->add(
                        'subject_id',
                        'The selected subject does not belong to this teaching area.'
                    );
                }
            }

            /*
             * Unit-level material cannot have a topic.
             */
            if (
                $this->input('scope') === 'unit' &&
                $this->filled('topic_id')
            ) {
                $validator->errors()->add(
                    'topic_id',
                    'Unit-level material cannot be assigned to a topic.'
                );

                return;
            }

            /*
             * Topic-level material requires a valid topic.
             */
            if ($this->input('scope') === 'topic') {

                if (!$this->filled('topic_id')) {
                    $validator->errors()->add(
                        'topic_id',
                        'A topic is required for topic-level material.'
                    );

                    return;
                }

                $topic = Topic::find($this->input('topic_id'));

                if (!$topic) {
                    return;
                }

                if ((int) $topic->unit_id !== (int) $unit->id) {
                    $validator->errors()->add(
                        'topic_id',
                        'The selected topic does not belong to the selected unit.'
                    );
                }

                if (
                    (int) $topic->grade_subject_id !==
                    (int) $unit->grade_subject_id
                ) {
                    $validator->errors()->add(
                        'topic_id',
                        'The selected topic does not belong to the selected teaching area.'
                    );
                }
            }
        });
    }
}