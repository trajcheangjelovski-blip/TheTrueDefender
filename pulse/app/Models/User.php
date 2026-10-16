<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'name', 'email', 'password',
    'slug', 'title', 'bio', 'credentials', 'photo_path',
    'x_url', 'linkedin_url', 'public_email', 'is_author', 'identity_status', 'identity_disclosure',
])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasRoles;

    /** Only users with at least one role may reach the admin panel. */
    public function canAccessPanel(Panel $panel): bool
    {
        return $this->roles()->exists();
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class, 'author_id');
    }

    /** Users who appear publicly as named authors (have a slug + are flagged). */
    public function scopeAuthors(\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder
    {
        return $query->where('is_author', true)->whereNotNull('slug');
    }

    /** Is this user a public, bylined author? */
    public function isPublicAuthor(): bool
    {
        return (bool) $this->is_author && filled($this->slug);
    }

    public function hasConfirmedProfile(): bool
    {
        return $this->identity_status === 'confirmed_person'
            || ($this->identity_status === 'confirmed_pen_name' && filled($this->identity_disclosure));
    }

    /** URL of this author's public page, or null if they are not a public author. */
    public function authorUrl(): ?string
    {
        return $this->isPublicAuthor() ? route('author.show', $this->slug) : null;
    }

    /** Absolute URL to the author's photo, or null. */
    public function photoUrl(): ?string
    {
        return filled($this->photo_path) ? asset('storage/' . ltrim($this->photo_path, '/')) : null;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
