# Laporan Perbaikan Modul REST API (Minggu 06)

Branch `w06` pada repositori `kampuslms-broken` memiliki 8 permasalahan pada modul REST API dan lapisan keamanannya. Berikut adalah rincian mengenai **apa yang salah sebelumnya**, **kenapa masalah tersebut harus diperbaiki**, serta **solusi perbaikannya**:

---

### 1. Pesan Login Membocorkan Keberadaan Email (*User Enumeration*)

* **Berkas Terkait:** `app/Http/Controllers/Api/AuthController.php`

* **Yang Salah Sebelumnya:**
  Pengecekan akun dan kata sandi dipisahkan dengan pesan kegagalan yang berbeda:
  ```php
  $user = User::where('email', $request->email)->first();

  if (! $user) {
      return response()->json(['message' => 'Email tidak terdaftar dalam sistem.'], 401);
  }

  if (! Hash::check($request->password, $user->password)) {
      return response()->json(['message' => 'Password yang Anda masukkan salah.'], 401);
  }
  ```
  *Akibat:* Penyerang dapat menebak email mana saja yang terdaftar di kampus hanya dari perbedaan pesan error.

* **Kenapa Harus Diperbaiki:**
  Pembedaan pesan kesalahan menimbulkan celah **User Enumeration** (pencacahan akun). Penyerang dapat mengirim daftar ribuan email dan memetakan dengan pasti mana saja akun civitas kampus yang valid terdaftar di sistem. Berdasarkan standar keamanan autentikasi OWASP, respon kegagalan login wajib diseragamkan agar tidak membocorkan status internal keberadaan akun.

* **Perbaikannya:**
  Menggabungkan pemeriksaan pengguna dan verifikasi password ke dalam satu percabangan kondisi logis, serta mengembalikan pesan kesalahan yang seragam:
  ```php
  $user = User::where('email', $request->email)->first();

  if (! $user || ! Hash::check($request->password, $user->password)) {
      RateLimiter::hit($throttleKey);

      return response()->json([
          'message' => 'Email atau password yang Anda masukkan salah.',
      ], 401);
  }
  ```

---

### 2. Login Tanpa Throttle (*Rate Limiting*)

* **Berkas Terkait:** `app/Http/Controllers/Api/AuthController.php`

* **Yang Salah Sebelumnya:**
  Mekanisme pembatasan frekuensi percobaan (*rate limiting*) dihapus sepenuhnya dari method `login()`. Penyerang dapat mengirim ribuan tebakan password per menit (*brute force*) tanpa hambatan.

* **Kenapa Harus Diperbaiki:**
  Tanpa pembatasan percobaan, endpoint login terbuka lebar terhadap serangan *brute force* dan *credential stuffing*. Bot otomatis dapat terus mencoba berbagai kombinasi password tanpa jeda, yang berpotensi membobol akun pengguna sekaligus membebani server autentikasi.

* **Perbaikannya:**
  Menerapkan facade native `RateLimiter` dari Laravel dengan batasan maksimal 5 kali percobaan gagal per kombinasi email dan IP:
  ```php
  $throttleKey = Str::transliterate(Str::lower($request->input('email')).'|'.$request->ip());

  if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
      $seconds = RateLimiter::availableIn($throttleKey);

      return response()->json([
          'message' => "Terlalu banyak percobaan login. Silakan coba lagi dalam {$seconds} detik.",
      ], 429);
  }
  ```
  Ketika login berhasil, counter percobaan dibersihkan:
  ```php
  RateLimiter::clear($throttleKey);
  ```

---

### 3. Satu Endpoint Tanpa `auth:sanctum` (Detail Course Terbuka)

* **Berkas Terkait:** `routes/api.php`

* **Yang Salah Sebelumnya:**
  Rute detail mata kuliah didefinisikan secara manual di luar grup middleware `auth:sanctum`:
  ```php
  Route::get('/courses/{course}', [CourseController::class, 'show']); // Terbuka untuk umum tanpa token
  ```

* **Kenapa Harus Diperbaiki:**
  Endpoint detail mata kuliah menyajikan informasi akademik internal kampus yang seharusnya hanya boleh diakses oleh pengguna yang sudah terautentikasi (*authenticated user*). Menempatkannya di luar middleware membuat data privat dapat dibaca bebas oleh pihak luar tanpa token Bearer.

* **Perbaikannya:**
  Memindahkan rute ke dalam grup middleware `auth:sanctum` dan memanfaatkan helper native `Route::apiResource()` untuk menyederhanakan rute:
  ```php
  Route::middleware('auth:sanctum')->name('api.')->group(function () {
      Route::get('/me', [AuthController::class, 'me'])->name('me');
      Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

      Route::apiResource('courses', CourseController::class); // Seluruh aksi (termasuk show) dilindungi Sanctum
  });
  ```

---

### 4. Masalah *N+1 Query* pada Endpoint Daftar Mata Kuliah

* **Berkas Terkait:** `app/Http/Controllers/Api/CourseController.php`

* **Yang Salah Sebelumnya:**
  Daftar mata kuliah diambil tanpa memuat relasi (*eager loading*):
  ```php
  $courses = Course::where('status', 'active')->paginate(10);

  return CourseResource::collection($courses);
  ```
  *Akibat:* Ketika `CourseResource` mengakses data dosen dan jumlah mahasiswa, Eloquent menjalankan kueri database berulang-ulang untuk tiap baris data ($1 + N$).

* **Kenapa Harus Diperbaiki:**
  Masalah *N+1 query* menyebabkan lonjakan jumlah kueri (*query explosion*). Jika satu halaman menampilkan 10 mata kuliah, sistem menjalankan 11 kueri terpisah ke database. Hal ini menimbulkan latensi tinggi, memperlambat respons API, dan membebani server basis data seiring bertambahnya volume data.

* **Perbaikannya:**
  Menerapkan *eager loading* native Eloquent `with('lecturer')` dan agregasi `withCount('students')` sebelum paginasi:
  ```php
  $courses = Course::with('lecturer')
      ->withCount('students')
      ->where('status', 'active')
      ->paginate(10);

  return CourseResource::collection($courses);
  ```

---

### 5. Status Code Salah pada Operasi `store` (Mengembalikan 200)

* **Berkas Terkait:** `app/Http/Controllers/Api/CourseController.php`

* **Yang Salah Sebelumnya:**
  Pembuatan data baru mengembalikan kode HTTP `200 OK`:
  ```php
  return response()->json(new CourseResource($course), 200); // Seharusnya 201
  ```

* **Kenapa Harus Diperbaiki:**
  Berdasarkan standar semantik protokol HTTP RESTful API, operasi penambahan resource baru yang berhasil harus mengembalikan kode `201 Created`. Mengembalikan `200 OK` membuat klien API (frontend SPA atau aplikasi mobile) tidak dapat membedakan secara pasti antara pengambilan data biasa dengan keberhasilan penciptaan entitas baru, serta menyebabkan kegagalan pada pengujian otomatis (*automated test*).

* **Perbaikannya:**
  Mengembalikan status code standar RESTful `201 Created`:
  ```php
  return (new CourseResource($course))->response()->setStatusCode(201); // 201 Created
  ```

---

### 6. Model Mentah Dikembalikan pada Dua Endpoint (`show` dan `update`)

* **Berkas Terkait:** `app/Http/Controllers/Api/CourseController.php`

* **Yang Salah Sebelumnya:**
  Method `show()` dan `update()` mengembalikan objek model Eloquent mentah secara langsung:
  ```php
  // Pada show():
  Gate::authorize('view', $course);
  return $course; // Model mentah

  // Pada update():
  Gate::authorize('update', $course);
  $course->update($validated);
  return $course; // Model mentah
  ```

* **Kenapa Harus Diperbaiki:**
  Mengembalikan model mentah berisiko membocorkan seluruh atribut database internal (*information disclosure*). Selain itu, format respons JSON menjadi tidak konsisten karena tidak memiliki wrapper `{"data": {...}}`, dan relasi penting seperti data dosen (`lecturer`) tidak ikut termuat.

* **Perbaikannya:**
  Membungkus output menggunakan `CourseResource` sebagai whitelist atribut yang diizinkan keluar ke publik, dan memuat relasi yang diperlukan menggunakan `load()`:
  ```php
  // Pada show():
  Gate::authorize('view', $course);
  $course->load(['lecturer', 'materials', 'assignments']);

  return new CourseResource($course); // Dibungkus API Resource

  // Pada update():
  Gate::authorize('update', $course);
  $course->update($validated);

  return new CourseResource($course); // Dibungkus API Resource
  ```

---

### 7. Status Code dan Payload Salah pada Operasi `destroy` (Mengembalikan 200)

* **Berkas Terkait:** `app/Http/Controllers/Api/CourseController.php`

* **Yang Salah Sebelumnya:**
  Operasi `destroy()` mengembalikan pesan teks JSON dengan status `200 OK`:
  ```php
  $course->delete();
  return response()->json(['message' => 'Mata kuliah berhasil dihapus'], 200); // Mengirim body dan status 200
  ```

* **Kenapa Harus Diperbaiki:**
  Sesuai standar spesifikasi HTTP RESTful API, operasi penghapusan data (`DELETE`) yang berhasil dan tidak membutuhkan payload balasan seharusnya mengembalikan status `204 No Content` tanpa body. Mengembalikan teks dan status 200 melanggar konvensi standar REST, memboroskan bandwidth jaringan, dan tidak sesuai dengan kontrak pengujian API.

* **Perbaikannya:**
  Menjalankan otorisasi penghapusan dan mengembalikan status `204 No Content` tanpa body:
  ```php
  Gate::authorize('delete', $course);
  $course->delete();

  return response()->json(null, 204); // 204 No Content
  ```

---

### 8. Pelanggaran Status Otorisasi (403 Dikembalikan Sebagai 401)

* **Berkas Terkait:** `bootstrap/app.php`

* **Yang Salah Sebelumnya:**
  Terdapat custom exception handler yang sengaja membelokkan seluruh kegagalan otorisasi menjadi status 401:
  ```php
  $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $e, $request) {
      if ($request->is('api/*')) {
          return response()->json(['message' => 'This action is unauthorized.'], 401); // 403 dibelokkan jadi 401
      }
  });
  ```
  *Akibat:* Klien yang sudah login secara sah (misal mahasiswa membawa token Bearer) ketika ditolak hak aksesnya justru menerima respon seolah-olah belum login (401).

* **Kenapa Harus Diperbaiki:**
  Kode status HTTP 401 dan 403 memiliki makna semantik yang berbeda:
  - `401 Unauthorized`: Klien belum terautentikasi (belum login atau token tidak valid). Tindakan klien: redirect ke form login.
  - `403 Forbidden`: Klien sudah terautentikasi, namun role atau izinnya tidak mencukupi untuk aksi tersebut (misal mahasiswa mencoba menghapus course dosen). Tindakan klien: tampilkan peringatan hak akses ditolak.
  Membelokkan 403 menjadi 401 membuat klien salah mengambil keputusan dan mengira sesi login mereka kedaluwarsa.

* **Perbaikannya:**
  Menghapus custom exception renderer tersebut sehingga Laravel menangani exception otorisasi secara native dan mengembalikan status yang tepat (`403 Forbidden`):
  ```php
  ->withExceptions(function (Exceptions $exceptions) {
      // Biarkan Laravel mengembalikan 403 Forbidden secara native
  })
  ```