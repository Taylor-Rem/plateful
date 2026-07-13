<?php

namespace App\Http\Requests\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UpdateHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'windows' => ['required', 'array'],
            'windows.*' => ['array'],
            'windows.*.*.opens_at' => ['required', 'date_format:H:i'],
            'windows.*.*.closes_at' => ['required', 'date_format:H:i'],
            'timezone' => ['nullable', 'string', 'timezone:all'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v) {
            $windows = $this->input('windows', []);
            if (! is_array($windows)) {
                return;
            }

            foreach ($windows as $day => $list) {
                if (! is_array($list)) {
                    continue;
                }

                $normalized = [];
                foreach ($list as $idx => $w) {
                    $opens = $w['opens_at'] ?? null;
                    $closes = $w['closes_at'] ?? null;
                    if (! is_string($opens) || ! is_string($closes)) {
                        continue;
                    }

                    if ($opens === $closes) {
                        $v->errors()->add(
                            "windows.$day.$idx.closes_at",
                            'Open and close times must differ.'
                        );

                        continue;
                    }

                    $normalized[] = [
                        'idx' => $idx,
                        'opens' => $opens,
                        'closes' => $closes,
                        'crosses_midnight' => $closes < $opens,
                    ];
                }

                // Overlap detection: only fully validated for non-midnight-crossing windows
                // on the same day. If any window crosses midnight, we accept it without
                // full cross-day overlap calc (pragmatic v1).
                $simple = array_values(array_filter($normalized, fn ($n) => ! $n['crosses_midnight']));
                usort($simple, fn ($a, $b) => strcmp($a['opens'], $b['opens']));

                for ($i = 1; $i < count($simple); $i++) {
                    $prev = $simple[$i - 1];
                    $cur = $simple[$i];
                    if ($cur['opens'] < $prev['closes']) {
                        $v->errors()->add(
                            "windows.$day.{$cur['idx']}.opens_at",
                            'Overlaps another window on the same day.'
                        );
                    }
                }
            }
        });
    }
}
