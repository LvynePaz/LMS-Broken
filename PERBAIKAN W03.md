# PERBAIKAN W03 — LMS Broken
Kelompok A07 — Pemrograman Web

---

### 7 Bug yang Ditemukan & Cara Benerin-nya

---

##### Bug ke 1: Urutan Migrasi Kebalik — `courses` Jalan Duluan Sebelum `users` Ada

**Intinya gimana?** Laravel jalanin file migrasi urut dari nama file yang paling kecil. Nah kalau tabel A butuh tabel B (lewat foreign key), ya tabel B harus dibuat duluan dong.

**Masalah-nya.** File `000001_create_courses_table.php` jalan **sebelum** `000002_create_users_table.php`. Padahal tabel `courses` punya kolom `lecturer_id` yang nunjuk ke tabel `users`. Jadinya pas `migrate:fresh` dijalanin, Laravel langsung error "Table 'users' doesn't exist" — ya wajar, belum dibuat kok langsung dirujuk.

**Sebelum (urutan salah):**
```
000001_create_courses_table.php   ← jalannya duluan, padahal butuh users yang belum ada
000002_create_users_table.php
000003_create_course_user_table.php
```

**Sesudah (rename file biar urutannya bener):**
```
000001_create_users_table.php         ← users dibuat duluan
000003_create_courses_table.php       ← baru courses
000004_create_course_user_table.php
000005_create_materials_table.php
000006_create_assignments_table.php
000007_create_submissions_table.php
000008_create_grades_table.php
000009_create_notifications_table.php
```

**Kalau dibiarain?** `php artisan migrate:fresh` bakal selalu error, CI langsung merah, dan satu tim gak bisa ngapa-ngapain.

---

##### Bug ke-2: `onDelete` Salah di `courses.lecturer_id` — Harusnya Restrict, Bukan Cascade

**Intinya gimana?** Ada dua pilihan perilaku pas data dihapus:
- `cascadeOnDelete` → hapus dosen, semua mata kuliahnya ikut kehapus juga. Kayak domino.
- `restrictOnDelete` → dosen **gak bisa dihapus** kalau masih ngajar mata kuliah. Lebih aman.

**Masalah-nya.** Spesifikasi Bagian 4.2 jelas nyebut `lecturer_id` harus `restrictOnDelete`. Tapi di W03 dipasang `cascadeOnDelete` — artinya kalau admin salah hapus akun dosen, seluruh data mata kuliah, materi, dan tugas miliknya bisa lenyap seketika tanpa konfirmasi.

**Sebelum:**
```php
$table->foreignId('lecturer_id')->constrained('users')->cascadeOnDelete();
```

**Sesudah:**
```php
$table->foreignId('lecturer_id')->constrained('users')->restrictOnDelete();
```

**Kalau dibiarain?** Admin yang gak sengaja hapus satu akun dosen bisa bikin semua data perkuliahan si dosen raib permanen. Gak bisa balik lagi.

---

##### Bug ke-3: Tabel `course_user` Gak Ada Unique Composite-nya

**Intinya gimana?** Unique composite itu constraint yang mastiin kombinasi dua kolom gak boleh sama. Kalau dipasang di `(course_id, user_id)`, artinya satu mahasiswa cuma bisa terdaftar **satu kali** di satu mata kuliah.

**Masalah-nya.** Constraint itu gak ada di W03, jadi mahasiswa bisa di-enroll berkali-kali ke MK yang sama. Data nilai dan rekap jadi berantakan.

**Sebelum:** Gak ada sama sekali.

**Sesudah:**
```php
$table->unique(['course_id', 'user_id']); // cegah enroll ganda
```

**Kalau dibiarain?** Satu mahasiswa bisa muncul dua kali (atau lebih) di `course_user` untuk MK yang sama. Rekap nilainya dobel, laporan presensinya rusak.

---

##### Bug ke-4: Tabel `submissions` Juga Gak Ada Unique Composite-nya

**Intinya gimana?** Spesifikasi Bagian 4.2 bilang 1 mahasiswa cuma boleh punya 1 submission aktif per tugas. Itu dijamin pakai `unique(['assignment_id', 'user_id'])`.

**Masalah-nya.** Constraint itu gak ada di W03, jadi mahasiswa bisa kumpulin tugas yang sama berkali-kali dan semuanya masuk ke database. Dosen bingung mau nilain yang mana.

**Sebelum:** Gak ada sama sekali.

**Sesudah:**
```php
$table->unique(['assignment_id', 'user_id']); // 1 mahasiswa 1 submission
```

**Kalau dibiarain?** Dosen nemu banyak submission dari mahasiswa yang sama buat satu tugas. Gak ada yang tahu mana versi finalnya.

---

##### Bug ke-5: Fungsi `down()` di Migrasi `materials` Kosong Melompong

**Intinya gimana?** Fungsi `down()` itu tugasnya ngebalik apa yang dikerjain `up()`. Simpelnya: kalau `up()` bikin tabel, `down()` harusnya hapus tabel itu. Kalau `down()` kosong, perintah `migrate:refresh` atau `rollback` bakal gagal.

**Masalah-nya.** File `create_materials_table.php` punya `down()` yang isinya... kosong. Pas `migrate:refresh` dijalanin, tabel `materials` gak ke-drop, terus pas `up()` jalan lagi muncul error "Table 'materials' already exists".

**Sebelum:**
```php
public function down(): void
{
    // kosong, gak ngapa-ngapain
}
```

**Sesudah:**
```php
public function down(): void
{
    Schema::dropIfExists('materials');
}
```

**Kalau dibiarain?** `php artisan migrate:refresh` selalu error. CI merah. Checkpoint minggu 3 gak bisa diverifikasi.

---

##### Bug ke-6: Model `User` Pakai `$guarded = []` — Semua Kolom Bisa Diisi Bebas

**Intinya gimana?** `$guarded = []` artinya gak ada kolom yang diproteksi — semua kolom, **termasuk `role`**, bisa langsung diisi dari input luar. `$fillable` yang isinya daftar spesifik adalah cara yang bener dan aman.

**Masalah-nya.** Karena pakai `$guarded = []`, siapa aja bisa ngirim `role=admin` di form atau lewat curl, dan Laravel langsung nyimpen itu tanpa protes. Ini namanya serangan *mass assignment* — dan ini yang bakal diuji saat interview.

**Sebelum:**
```php
protected $guarded = [];
```

**Sesudah:**
```php
protected $fillable = ['name', 'email', 'password', 'nim_nip'];
// 'role' sengaja gak masuk fillable — diisi eksplisit di controller
```

**Kalau dibiarain?** Semua orang bisa daftar jadi `admin` cukup dengan nambahin `role=admin` di body request POST. Serius bahaya.

---

##### Bug ke-7: `UserController` Masih Pakai `$request->all()` — Pintu Mass Assignment Lewat Controller

**Intinya gimana?** Meski `$fillable` di model udah bener, kalau controller-nya masih nerusin semua input tanpa validasi ke `create()` / `update()`, tetap berbahaya. Yang bener: validasi dulu, baru isi kolom sensitif seperti `role` secara **eksplisit**.

**Masalah-nya.** Method `store()` dan `update()` di `UserController` langsung panggil `User::create($request->all())` dan `$user->update($request->all())` — tanpa validasi apapun. Format email gak dicek, password bisa kosong, `role` bisa diisi nilai sembarangan.

**Sebelum:**
```php
public function store(Request $request)
{
    User::create($request->all()); // bahaya banget ini
}

public function update(Request $request, User $user)
{
    $user->update($request->all()); // ini juga
}
```

**Sesudah:**
```php
public function store(Request $request)
{
    $validated = $request->validate([
        'name'     => 'required|string|max:255',
        'email'    => 'required|email|unique:users,email',
        'password' => 'required|string|min:8',
        'nim_nip'  => 'nullable|string|unique:users,nim_nip',
        'role'     => 'required|in:admin,dosen,mahasiswa',
    ]);

    User::create([
        'name'     => $validated['name'],
        'email'    => $validated['email'],
        'password' => $validated['password'],
        'nim_nip'  => $validated['nim_nip'] ?? null,
        'role'     => $validated['role'],  // diisi eksplisit setelah validasi
    ]);
}
```

**Kalau dibiarain?** Tanpa validasi, input asal-asalan masuk ke database. Dan kolom `role` tetap bisa dimanipulasi dari luar meski `$fillable` udah dibener-in.

---

##### Bukti Semua Bug Udah Kelar

aku udh lakukan hal ini dan hijau tanpa error:

```bash
php artisan migrate:fresh
php artisan migrate:refresh
```

