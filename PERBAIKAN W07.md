# Laporan Perbaikan Keamanan & Otorisasi (Branch W07) — LMS Broken

Branch `w07` pada repositori `kampuslms-broken` memiliki 9 masalah kritis terkait autentikasi, otorisasi, kebocoran data, dan performa query:

1. `@can` di Blade tanpa `Gate::authorize()` di controller (`CourseController` dan `MaterialController`),
2. `Policy` yang selalu `return true` pada `SubmissionPolicy`,
3. Kebocoran daftar mata kuliah untuk mahasiswa di `CourseController::index()`,
4. Role pengguna bisa diubah lewat update profil (`UserController::update()`),
5. `session()->regenerate()` hilang saat login berhasil,
6. Pesan login membocorkan keberadaan email (*User Enumeration*),
7. Satu route dosen (`/courses/create`) diletakkan di luar middleware `auth`,
8. Policy yang menyebabkan N+1 dan konsumsi memori tinggi di `CoursePolicy::view()`,
9. `Gate::authorize('viewAny', User::class)` hilang di `UserController::index()`.

Perubahan dilakukan pada berkas-berkas berikut:
- `app/Http/Controllers/AuthController.php`
- `routes/web.php`
- `app/Http/Controllers/CourseController.php`
- `app/Http/Controllers/MaterialController.php`
- `app/Http/Controllers/UserController.php`
- `app/Policies/CoursePolicy.php`
- `app/Policies/SubmissionPolicy.php`
- `tests/Feature/SecurityW07Test.php` (Test Regresi Baru)

---

## 1. Mencegah Kebocoran Keberadaan Email pada Login (User Enumeration)

### Masalah
Sebelumnya, `AuthController::login()` mengecek keberadaan email secara terpisah sebelum autentikasi password:
```php
if (! User::where('email', $credentials['email'])->exists()) {
    throw ValidationException::withMessages([
        'email' => 'Email tidak terdaftar.',
    ]);
}
// ...
throw ValidationException::withMessages([
    'email' => 'Password yang Anda masukkan salah.',
]);
```
Perbedaan pesan ini membocorkan status apakah suatu akun email terdaftar di sistem. Penyerang dapat menyusun daftar email valid (*User Enumeration*) untuk target *brute force* atau *phishing*.

### Perubahan
Pengecekan keberadaan email dihapus, dan pesan kegagalan diseragamkan menjadi pesan generik:
```php
throw ValidationException::withMessages([
    'email' => 'Email atau password yang Anda masukkan salah.',
]);
```

### Dampak
- Penyerang tidak bisa membedakan antara email yang belum terdaftar dan password yang salah.
- Informasi internal basis data terlindungi dari *account harvesting*.

---

## 2. Mengembalikan `session()->regenerate()` saat Login Sukses

### Masalah
Setelah `Auth::attempt(...)` berhasil, baris regenerasi ID session dihilangkan:
```php
if (Auth::attempt($credentials, $request->boolean('remember'))) {
    RateLimiter::clear($throttleKey);
    // session()->regenerate() hilang!
    return redirect()->intended(route('dashboard'));
}
```
Tanpa regenerasi ID session, ID session sebelum login tetap dipertahankan setelah login. Penyerang yang telah menanamkan ID session sebelumnya (*Session Fixation*) dapat langsung membajak akun korban setelah korban login.

### Perubahan
Menambahkan kembali pemanggilan regenerasi session:
```php
if (Auth::attempt($credentials, $request->boolean('remember'))) {
    RateLimiter::clear($throttleKey);
    $request->session()->regenerate();

    return redirect()->intended(route('dashboard'));
}
```

### Dampak
- ID session diacak ulang seketika setelah kredensial terverifikasi.
- Mencegah serangan *Session Fixation* dan pembajakan cookie sesi lama.

---

## 3. Melindungi Route Dosen (`courses.create`) dengan Middleware `auth`

### Masalah
Di `routes/web.php`, route pembuatan mata kuliah diletakkan di luar grup middleware `auth`:
```php
Route::get('/courses/create', [CourseController::class, 'create'])->name('courses.create');

Route::middleware('auth')->group(function () {
    // ...
```
Pengguna yang belum login (*guest*) dapat langsung membuka form pembuatan mata kuliah.

### Perubahan
Memindahkan route tersebut ke dalam grup middleware `auth` sebelum rute wildcard `{course}`:
```php
Route::middleware('auth')->group(function () {
    // ...
    Route::get('/courses', [CourseController::class, 'index'])->name('courses.index');
    Route::get('/courses/create', [CourseController::class, 'create'])->name('courses.create');
    Route::post('/courses', [CourseController::class, 'store'])->name('courses.store');
    Route::get('/courses/{course}', [CourseController::class, 'show'])->name('courses.show');
```

### Dampak
- Tamu yang mencoba mengakses `/courses/create` otomatis diarahkan (*redirect 302*) ke halaman login.
- Alur otentikasi menjadi konsisten dan aman.

---

## 4. Menutup Kebocoran Daftar Mata Kuliah Mahasiswa di `CourseController::index()`

### Masalah
Query pada method `index()` mengambil seluruh mata kuliah tanpa memfilter berdasarkan peran pengguna:
```php
$query = Course::with('lecturer')->withCount('students');
```
Mahasiswa dapat melihat seluruh mata kuliah yang ada di LMS, termasuk mata kuliah dari program studi lain atau mata kuliah yang tidak diikutinya.

### Perubahan
Menyaring data di tingkat basis data (*query level*) menggunakan ekspresi `match`:
```php
$user = $request->user();
$role = $user->role ?: 'mahasiswa';
$query = (match ($role) {
    'admin'     => Course::query(),
    'dosen'     => $user->taughtCourses(),
    'mahasiswa' => $user->courses(),
    default     => abort(403),
})->with('lecturer')->withCount('students');
```

### Dampak
- Mahasiswa hanya melihat mata kuliah yang diikutinya (`$user->courses()`).
- Dosen hanya melihat mata kuliah yang diampunya (`$user->taughtCourses()`).
- Admin tetap dapat melihat seluruh mata kuliah untuk keperluan pengawasan.

---

## 5. Memasang Otorisasi Server-Side di `CourseController` (`update` & `destroy`)

### Masalah
Tombol Edit dan Hapus di Blade dibungkus `@can('update', $course)` dan `@can('delete', $course)`. Namun di controller:
```php
public function update(Request $request, Course $course)
{
    // Gate::authorize('update', $course) HILANG!
    $validated = $request->validate([...]);
    $course->update($validated);
}

public function destroy(Course $course)
{
    // Gate::authorize('delete', $course) HILANG!
    $course->delete();
}
```
Direktif `@can` hanya menyembunyikan elemen visual di browser. Penyerang atau mahasiswa yang mengirim request HTTP `PUT` atau `DELETE` via cURL atau Postman tetap dapat mengubah dan menghapus mata kuliah milik dosen lain.

### Perubahan
Menambahkan `Gate::authorize()` di awal masing-masing method:
```php
public function update(Request $request, Course $course)
{
    Gate::authorize('update', $course);
    // ...
}

public function destroy(Course $course)
{
    Gate::authorize('delete', $course);
    // ...
}
```

### Dampak
- Akses langsung via HTTP PUT/DELETE tanpa wewenang akan diblokir dengan respons `403 Forbidden`.
- Menegakkan prinsip pertahanan ganda: `@can` untuk UI, `Gate::authorize` untuk keamanan server.

---

## 6. Memasang Otorisasi `Gate::authorize('delete', $material)` di `MaterialController`

### Masalah
Method `destroy()` pada `MaterialController` tidak memiliki pemeriksaan otorisasi:
```php
public function destroy(Material $material)
{
    // Tanpa Gate::authorize!
    $course = $material->course;
    // ...
    $material->delete();
}
```
Siapa pun pengguna yang sedang login (termasuk mahasiswa) dapat menghapus file materi pembelajaran dosen hanya dengan mengirim request `DELETE /materials/{id}`.

### Perubahan
Menambahkan otorisasi menggunakan Policy:
```php
public function destroy(Material $material)
{
    Gate::authorize('delete', $material);

    $course = $material->course;
    // ...
```

### Dampak
- Hanya dosen pengampu, pemilik unggahan, atau admin yang diizinkan menghapus materi. Mahasiswa akan menerima `403 Forbidden`.

---

## 7. Mencegah Eskalasi Hak Akses Role di `UserController::update()`

### Masalah
Pengecekan izin ubah role dihilangkan:
```php
if ($request->has('role')) {
    $request->validate(['role' => 'required|in:admin,dosen,mahasiswa']);
    $user->role = $request->role;
}
```
Pengguna biasa yang mengedit profilnya dapat menyisipkan `role=admin` dalam form/request, sehingga mengangkat dirinya sendiri menjadi administrator (*Privilege Escalation*).

### Perubahan
Mengembalikan pemeriksaan `Gate::allows('updateRole', $user)` yang mengacu pada `UserPolicy::updateRole`:
```php
if ($request->has('role') && Gate::allows('updateRole', $user)) {
    $request->validate(['role' => 'required|in:admin,dosen,mahasiswa']);
    $user->role = $request->role;
}
```

### Dampak
- Hanya admin yang dapat mengganti role pengguna. Input `role` dari pengguna non-admin diabaikan dengan aman.

---

## 8. Mengamankan Akses Daftar Pengguna di `UserController::index()`

### Masalah
Pemanggilan otorisasi dihapus dari method `index()`:
```php
public function index(Request $request)
{
    // Gate::authorize('viewAny', User::class) HILANG!
    $query = User::query();
```
Menu pengguna disembunyikan di navbar untuk non-admin, tetapi URL `/users` tetap terbuka bebas untuk mahasiswa dan dosen.

### Perubahan
Menambahkan kembali otorisasi di baris pertama:
```php
public function index(Request $request)
{
    Gate::authorize('viewAny', User::class);

    $query = User::query();
```

### Dampak
- Mengakses `/users` tanpa peran admin akan mengembalikan respons `403 This action is unauthorized.`.

---

## 9. Menghapus Bypass Otorisasi di `SubmissionPolicy`

### Masalah
Terdapat method `before()` yang selalu mengembalikan `true`:
```php
public function before(User $user, string $ability): ?bool
{
    return true; // ❌ Seluruh aturan otorisasi diabaikan
}
```
Method `before()` dieksekusi sebelum method policy lainnya. Karena selalu me-return `true`, mahasiswa dapat mengunduh dan melihat lembar jawaban tugas milik mahasiswa lain (*IDOR*).

### Perubahan
Method `before()` yang mengembalikan `true` dihapus seluruhnya dari `SubmissionPolicy.php`, sehingga logika otorisasi kembali ke aturan individual (`view`, `viewAny`, `grade`, dll.).

### Dampak
- Mahasiswa hanya dapat melihat submission miliknya sendiri. Akses ke submission mahasiswa lain menghasilkan status `403 Forbidden`.

---

## 10. Optimasi Query Relasi Mahasiswa di `CoursePolicy` (Cegah N+1)

### Masalah
Pemeriksaan keanggotaan mahasiswa menggunakan pemanggilan koleksi:
```php
return $course->students->pluck('id')->contains($user->id);
```
Sintaks properti `$course->students` mengeksekusi query untuk memuat seluruh baris mahasiswa ke dalam memori PHP, memicu masalah performa N+1 dan pemborosan RAM.

### Perubahan
Menggunakan method relasi dengan klausul `exists()` di level SQL:
```php
return $course->students()->where('user_id', $user->id)->exists();
```

### Dampak
- Mengurangi beban memori dan query dijalankan cepat dalam bentuk `SELECT 1 FROM course_user WHERE ... LIMIT 1`.

---

## Bukti Pengujian cURL (Minimal 3 Kasus)

Berikut adalah pengujian bukti keamanan menggunakan perintah cURL:

### 1. Bukti Pencegahan User Enumeration (Masalah #1)
Menguji pengiriman request login dengan email yang tidak terdaftar:
```bash
curl -i -X POST http://127.0.0.1:8000/login \
  -H "Accept: application/json" \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "email=tidakada@test.com&password=rahasiasembarang"
```
**Respons Server:**
```json
HTTP/1.1 422 Unprocessable Content
Content-Type: application/json

{
  "message": "Email atau password yang Anda masukkan salah.",
  "errors": {
    "email": [
      "Email atau password yang Anda masukkan salah."
    ]
  }
}
```
*Hasil:* Respons server tidak menyebutkan "Email tidak terdaftar", melainkan pesan netral yang identik dengan kesalahan kata sandi.

---

### 2. Bukti Pencegahan Akses Route Form Dosen oleh Guest (Masalah #3)
Mencoba mengakses route pembuatan mata kuliah tanpa login (*guest*):
```bash
curl -i -X GET http://127.0.0.1:8000/courses/create
```
**Respons Server:**
```http
HTTP/1.1 302 Found
Location: http://127.0.0.1:8000/login
```
*Hasil:* Tamu langsung dialihkan (302 Redirect) ke halaman login dan tidak dapat melihat form pembuatan course.

---

### 3. Bukti Penolakan HTTP PUT Manipulasi Course oleh Mahasiswa (Masalah #5)
Simulasi mahasiswa mencoba mengubah data mata kuliah dosen lain via request PUT langsung:
```bash
curl -i -X POST http://127.0.0.1:8000/courses/1 \
  -b "cookies_mahasiswa.txt" \
  -H "X-CSRF-TOKEN: [TOKEN]" \
  -d "_method=PUT" \
  -d "code=SI101&name=Mata+Kuliah+Dirusak&sks=3&status=active&lecturer_id=1"
```
**Respons Server:**
```http
HTTP/1.1 403 Forbidden

{
  "message": "This action is unauthorized."
}
```
*Hasil:* Meskipun tombol edit disembunyikan di Blade, pengiriman langsung melalui HTTP request ditolak tegas dengan kode status `403 Forbidden`.

---

### 4. Bukti Penolakan Eskalasi Role Menjadi Admin (Masalah #7)
Simulasi mahasiswa mencoba mengubah perannya sendiri menjadi `admin` saat update profil:
```bash
curl -i -X POST http://127.0.0.1:8000/users/2 \
  -b "cookies_mahasiswa.txt" \
  -H "X-CSRF-TOKEN: [TOKEN]" \
  -d "_method=PUT" \
  -d "name=Budi&email=budi@test.com&role=admin"
```
**Respons Server:**
```http
HTTP/1.1 302 Found
Location: http://127.0.0.1:8000/users
```
*Pengecekan Basis Data:*
Nilai kolom `role` pada baris pengguna tetap bernilai `"mahasiswa"`. Input `role=admin` diabaikan karena dicegah oleh `Gate::allows('updateRole', $user)`.

---

## Hasil Test Regresi Otomatis

Seluruh pengujian unit dan fitur (termasuk 8 test kasus regresi keamanan baru pada `SecurityW07Test.php`) telah dijalankan dan berstatus **HIJAU / PASS**:

```bash
$ php artisan test

   PASS  Tests\Unit\ExampleTest
  ✓ that true is true

   PASS  Tests\Feature\ApiTest
  ✓ api login returns token
  ✓ unauthenticated api request returns 401
  ✓ authenticated api request returns course list
  ✓ api store returns 201 created

   PASS  Tests\Feature\AuthTest
  ✓ login screen can be rendered
  ✓ users can authenticate using the login screen
  ✓ users can not authenticate with invalid password

   PASS  Tests\Feature\CourseTest
  ✓ authenticated user can view courses list
  ✓ dosen can create a course
  ✓ mahasiswa cannot create a course

   PASS  Tests\Feature\ExampleTest
  ✓ the application returns a successful response

   PASS  Tests\Feature\SecurityW07Test
  ✓ login does not reveal email existence
  ✓ guest cannot access courses create route
  ✓ student only sees enrolled courses
  ✓ mahasiswa cannot update or delete course
  ✓ mahasiswa cannot delete material
  ✓ student cannot view other student submission
  ✓ user cannot escalate their own role
  ✓ non admin cannot access user index

  Tests:    20 passed (40 assertions)
  Duration: 1.88s
```
