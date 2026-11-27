<?php

namespace Classivo\Fees\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * Student categories (RTE, staff ward, ...) belong to the students package.
 * Fee masters can be priced per category only when that model is installed;
 * otherwise the category dimension is hidden and ignored.
 */
final class StudentCategories
{
    /** @return class-string<Model> */
    public static function model(): string
    {
        return (string) config('fees.student_category_model', 'Classivo\\Students\\Models\\StudentCategory');
    }

    public static function available(): bool
    {
        return class_exists(self::model());
    }
}
