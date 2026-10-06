<?php

namespace lhaamed\MediaModule\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Validates an uploaded file against the global deny-list and optional per-field restrictions.
 *
 * Checks, in order (the first failure wins):
 *  1. Blocked extensions: every extension segment of the client file name is checked against
 *     `config('media.blocked_extensions')`, so "shell.php.jpg" is rejected.
 *  2. Allowed extensions: when `$extensions` is given, the final extension must be one of them.
 *  3. Allowed MIME types: when `$mimes` is given, the MIME type detected from the file content
 *     must match one of them. Wildcards are supported ("image/*").
 *
 * An empty `$mimes` / `$extensions` means "no restriction of this kind".
 *
 *     'avatar' => ['required', 'file', new AllowedUpload(mimes: ['image/*'])]
 *     'report' => ['required', 'file', new AllowedUpload(extensions: ['pdf', 'xlsx'])]
 */

class AllowedUpload implements ValidationRule
{
    /**
     * @param string[] $mimes       Allowed MIME types or patterns, e.g. ['image/png', 'video/*'].
     * @param string[] $extensions  Allowed lowercase extensions without a dot, e.g. ['pdf', 'png'].
     */
    public function __construct(
        protected array $mimes = [],
        protected array $extensions = [],
    ) {}

    /**
     * Fails when the file name contains a blocked extension, when its final extension
     * is not in `$extensions`, or when its content MIME type does not match `$mimes`.
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $value instanceof UploadedFile || ! $value->isValid()) {
            $fail('فایل معتبر نیست.');
            return;
        }

        $parts = explode('.', strtolower($value->getClientOriginalName()));
        array_shift($parts);

        if (array_intersect($parts, config('media.blocked_extensions', []))) {
            $fail('این نوع فایل مجاز نیست.');
            return;
        }

        if ($this->extensions && ! in_array(end($parts), $this->extensions, true)) {
            $fail('پسوند فایل مجاز نیست.');
            return;
        }

        if ($this->mimes && ! Str::is($this->mimes, (string) $value->getMimeType())) {
            $fail('فرمت فایل مجاز نیست.');
        }
    }

    /**
     * Builds the value for an HTML `accept` attribute from the allowed extensions and MIME types,
     * e.g. ".pdf,.xlsx,image/*". Returns null when the rule has no restrictions.
     *
     * This is a client-side hint only; the server-side validation is the real check.
     *
     *     <input type="file" accept="{{ $rule->accept() }}">
     */
    public function accept(): ?string
    {
        $accept = collect($this->extensions)
            ->map(fn ($e) => ".$e")
            ->merge($this->mimes)
            ->implode(',');

        return $accept ?: null;
    }

}
