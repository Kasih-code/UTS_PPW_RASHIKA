<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

// Helper function untuk menentukan status usia aset
if (!function_exists('statusUsiaAset')) {
    function statusUsiaAset(int $tahun): string {
        return $tahun >= 2023 ? 'Baru' : 'Lama';
    }
}

Route::get('/', function () {
    return redirect()->route('inventaris.index');
});

// 1. READ, PENCARIAN, DAN FILTER
Route::get('/inventaris', function (Request $request) {
    $kataKunci = trim(strip_tags((string) $request->query('q', '')));
    $ruangId = $request->query('ruang_id', '');

    try {
        $pdo = DB::connection()->getPdo();

        $sql = 'SELECT i.id, i.kode_barang, i.nama_barang, i.nomor_aset, 
                       i.tahun_perolehan, i.ruang_id, r.nama_ruang
                FROM inventaris AS i
                JOIN ruang AS r ON r.id = i.ruang_id
                WHERE 1 = 1';
        
        $parameter = [];

        if ($kataKunci !== '') {
            $sql .= ' AND (i.kode_barang LIKE :q_kode 
                      OR i.nama_barang LIKE :q_nama 
                      OR i.nomor_aset LIKE :q_aset)';
            $nilaiCari = '%' . $kataKunci . '%';
            $parameter['q_kode'] = $nilaiCari;
            $parameter['q_nama'] = $nilaiCari;
            $parameter['q_aset'] = $nilaiCari;
        }

        if ($ruangId !== '') {
            $sql .= ' AND i.ruang_id = :ruang_id';
            $parameter['ruang_id'] = (int) $ruangId;
        }

        $sql .= ' ORDER BY i.kode_barang';

        $stmtInventaris = $pdo->prepare($sql);
        $stmtInventaris->execute($parameter);
        $daftarInventaris = $stmtInventaris->fetchAll(\PDO::FETCH_ASSOC);

        $stmtRuang = $pdo->prepare('SELECT id, nama_ruang FROM ruang ORDER BY nama_ruang');
        $stmtRuang->execute();
        $daftarRuang = $stmtRuang->fetchAll(\PDO::FETCH_ASSOC);

        return view('inventaris', compact(
            'daftarInventaris',
            'daftarRuang',
            'kataKunci',
            'ruangId'
        ));
    } catch (\Throwable $error) {
        report($error);
        return response('Gagal memuat data inventaris. Silakan periksa koneksi basis data.', 500);
    }
})->name('inventaris.index');

// 2. CREATE (STORE)
Route::post('/inventaris', function (Request $request) {
    $dataInput = [
        'kode_barang' => strtoupper(trim(strip_tags((string) $request->input('kode_barang')))),
        'nama_barang' => trim(strip_tags((string) $request->input('nama_barang'))),
        'nomor_aset' => trim(strip_tags((string) $request->input('nomor_aset'))),
        'tahun_perolehan' => trim((string) $request->input('tahun_perolehan')),
        'ruang_id' => trim((string) $request->input('ruang_id')),
    ];

    $validator = Validator::make($dataInput, [
        'kode_barang' => ['required', 'string', 'size:10', 'alpha_num'],
        'nama_barang' => ['required', 'string', 'min:3', 'max:100'],
        'nomor_aset' => ['required', 'string', 'min:5', 'max:30'],
        'tahun_perolehan' => ['required', 'integer', 'between:2000,2026'],
        'ruang_id' => ['required', 'integer'],
    ], [
        'kode_barang.required' => 'Kode barang wajib diisi.',
        'kode_barang.size' => 'Kode barang harus tepat 10 karakter.',
        'kode_barang.alpha_num' => 'Kode barang harus berupa alfanumerik.',
        'nama_barang.required' => 'Nama barang wajib diisi.',
        'nama_barang.min' => 'Nama barang minimal 3 karakter.',
        'nomor_aset.required' => 'Nomor aset wajib diisi.',
        'nomor_aset.min' => 'Nomor aset minimal 5 karakter.',
        'nomor_aset.max' => 'Nomor aset maksimal 30 karakter.',
        'tahun_perolehan.required' => 'Tahun perolehan wajib diisi.',
        'tahun_perolehan.between' => 'Tahun perolehan harus antara 2000 hingga 2026.',
        'ruang_id.required' => 'Ruang wajib dipilih.',
    ]);

    if ($validator->fails()) {
        return back()->withErrors($validator)->withInput();
    }

    $valid = $validator->validated();

    try {
        $pdo = DB::connection()->getPdo();

        $stmtCek = $pdo->prepare(
            'SELECT COUNT(*) FROM inventaris WHERE kode_barang = :kode OR nomor_aset = :aset'
        );
        $stmtCek->execute([
            'kode' => $valid['kode_barang'],
            'aset' => $valid['nomor_aset'],
        ]);

        if ((int) $stmtCek->fetchColumn() > 0) {
            return back()->withInput()->with('gagal', 'Kode barang atau Nomor Aset sudah digunakan.');
        }

        $stmtSimpan = $pdo->prepare(
            'INSERT INTO inventaris (kode_barang, nama_barang, nomor_aset, tahun_perolehan, ruang_id)
             VALUES (:kode, :nama, :aset, :tahun, :ruang_id)'
        );
        $stmtSimpan->execute([
            'kode' => $valid['kode_barang'],
            'nama' => $valid['nama_barang'],
            'aset' => $valid['nomor_aset'],
            'tahun' => (int) $valid['tahun_perolehan'],
            'ruang_id' => (int) $valid['ruang_id'],
        ]);

        return redirect()->route('inventaris.index')->with('berhasil', 'Data inventaris berhasil ditambahkan.');
    } catch (\Throwable $error) {
        report($error);
        return back()->withInput()->with('gagal', 'Data gagal disimpan akibat kesalahan sistem.');
    }
})->name('inventaris.store');

// 3. EDIT FORM
Route::get('/inventaris/{id}/edit', function (int $id) {
    try {
        $pdo = DB::connection()->getPdo();

        $stmt = $pdo->prepare(
            'SELECT id, kode_barang, nama_barang, nomor_aset, tahun_perolehan, ruang_id 
             FROM inventaris WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $inventaris = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$inventaris) {
            return redirect()->route('inventaris.index')->with('gagal', 'Data inventaris tidak ditemukan.');
        }

        $stmtRuang = $pdo->prepare('SELECT id, nama_ruang FROM ruang ORDER BY nama_ruang');
        $stmtRuang->execute();
        $daftarRuang = $stmtRuang->fetchAll(\PDO::FETCH_ASSOC);

        return view('inventaris_edit', compact('inventaris', 'daftarRuang'));
    } catch (\Throwable $error) {
        report($error);
        return redirect()->route('inventaris.index')->with('gagal', 'Gagal memuat form edit.');
    }
})->name('inventaris.edit');

// 4. UPDATE
Route::put('/inventaris/{id}', function (Request $request, int $id) {
    $dataInput = [
        'kode_barang' => strtoupper(trim(strip_tags((string) $request->input('kode_barang')))),
        'nama_barang' => trim(strip_tags((string) $request->input('nama_barang'))),
        'nomor_aset' => trim(strip_tags((string) $request->input('nomor_aset'))),
        'tahun_perolehan' => trim((string) $request->input('tahun_perolehan')),
        'ruang_id' => trim((string) $request->input('ruang_id')),
    ];

    $validator = Validator::make($dataInput, [
        'kode_barang' => ['required', 'string', 'size:10', 'alpha_num'],
        'nama_barang' => ['required', 'string', 'min:3', 'max:100'],
        'nomor_aset' => ['required', 'string', 'min:5', 'max:30'],
        'tahun_perolehan' => ['required', 'integer', 'between:2000,2026'],
        'ruang_id' => ['required', 'integer'],
    ], [
        'kode_barang.required' => 'Kode barang wajib diisi.',
        'kode_barang.size' => 'Kode barang harus tepat 10 karakter.',
        'kode_barang.alpha_num' => 'Kode barang harus berupa alfanumerik.',
        'nama_barang.required' => 'Nama barang wajib diisi.',
        'nama_barang.min' => 'Nama barang minimal 3 karakter.',
        'nomor_aset.required' => 'Nomor aset wajib diisi.',
        'nomor_aset.min' => 'Nomor aset minimal 5 karakter.',
        'nomor_aset.max' => 'Nomor aset maksimal 30 karakter.',
        'tahun_perolehan.required' => 'Tahun perolehan wajib diisi.',
        'tahun_perolehan.between' => 'Tahun perolehan harus antara 2000 hingga 2026.',
        'ruang_id.required' => 'Ruang wajib dipilih.',
    ]);

    if ($validator->fails()) {
        return back()->withErrors($validator)->withInput();
    }

    $valid = $validator->validated();

    try {
        $pdo = DB::connection()->getPdo();

        $stmtCek = $pdo->prepare(
            'SELECT COUNT(*) FROM inventaris 
             WHERE (kode_barang = :kode OR nomor_aset = :aset) AND id <> :id'
        );
        $stmtCek->execute([
            'kode' => $valid['kode_barang'],
            'aset' => $valid['nomor_aset'],
            'id' => $id,
        ]);

        if ((int) $stmtCek->fetchColumn() > 0) {
            return back()->withInput()->with('gagal', 'Kode barang atau Nomor Aset sudah digunakan barang lain.');
        }

        $stmtUbah = $pdo->prepare(
            'UPDATE inventaris 
             SET kode_barang = :kode, nama_barang = :nama, nomor_aset = :aset, 
                 tahun_perolehan = :tahun, ruang_id = :ruang_id
             WHERE id = :id'
        );
        $stmtUbah->execute([
            'kode' => $valid['kode_barang'],
            'nama' => $valid['nama_barang'],
            'aset' => $valid['nomor_aset'],
            'tahun' => (int) $valid['tahun_perolehan'],
            'ruang_id' => (int) $valid['ruang_id'],
            'id' => $id,
        ]);

        return redirect()->route('inventaris.index')->with('berhasil', 'Data inventaris berhasil diperbarui.');
    } catch (\Throwable $error) {
        report($error);
        return back()->withInput()->with('gagal', 'Data gagal diperbarui akibat kesalahan sistem.');
    }
})->name('inventaris.update');

// 5. DELETE
Route::delete('/inventaris/{id}', function (int $id) {
    try {
        $pdo = DB::connection()->getPdo();

        $stmtHapus = $pdo->prepare('DELETE FROM inventaris WHERE id = :id');
        $stmtHapus->execute(['id' => $id]);

        if ($stmtHapus->rowCount() === 0) {
            return redirect()->route('inventaris.index')->with('gagal', 'Data inventaris tidak ditemukan.');
        }

        return redirect()->route('inventaris.index')->with('berhasil', 'Data inventaris berhasil dihapus.');
    } catch (\Throwable $error) {
        report($error);
        return redirect()->route('inventaris.index')->with('gagal', 'Data gagal dihapus akibat kesalahan sistem.');
    }
})->name('inventaris.destroy');