<?php

namespace lhaamed\MediaModule\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Str;

class AllowedUpload implements ValidationRule
{
    public function __construct(
        protected array $mimes = [],
        protected array $extensions = [],
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $parts = explode('.', strtolower($value->getClientOriginalName()));
        array_shift($parts);

        if (array_intersect($parts, config('media.blocked_extensions'))) {
            $fail('این نوع فایل مجاز نیست.');
            return;
        }

        if ($this->extensions && ! in_array(end($parts), $this->extensions, true)) {
            $fail('پسوند فایل مجاز نیست.');
            return;
        }

        if ($this->mimes && ! Str::is($this->mimes, $value->getMimeType())) {
            $fail('فرمت فایل مجاز نیست.');
        }
    }

    public function accept(): ?string
    {
        $accept = collect($this->extensions)
            ->map(fn ($e) => ".$e")
            ->merge($this->mimes)
            ->implode(',');

        return $accept ?: null;
    }

}
