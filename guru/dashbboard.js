window.onpageshow = function (event) {
  if (event.persisted) {
    window.location.reload();
  }
};

const infoBagian = {
  beranda: ["Beranda", "Ringkasan mengajarmu hari ini"],
  absensi: ["Absensi", "Catat kehadiran siswa dengan cepat"],
  siswa: ["Daftar Siswa", "Semua siswa yang terdaftar di portal"],
  jadwal: ["Jadwal Mengajar", "Atur jadwal mengajar mingguanmu"],
  agenda: ["Agenda", "Rencanakan tugas dan kegiatan mengajarmu"],
  catatan: ["Catatan", "Simpan catatan penting untuk kelasmu"],
};

const semuaBagian = document.querySelectorAll(".bagian");
const semuaNav = document.querySelectorAll(".nav");
const judulBagian = document.getElementById("judulBagian");
const subBagian = document.getElementById("subBagian");

function bukaBagian(nama) {
  if (!infoBagian[nama]) {
    nama = "beranda";
  }
  semuaBagian.forEach((b) => b.classList.toggle("aktif", b.id === nama));
  semuaNav.forEach((n) =>
    n.classList.toggle("aktif", n.dataset.bagian === nama),
  );
  judulBagian.textContent = infoBagian[nama][0];
  subBagian.textContent = infoBagian[nama][1];
  history.replaceState(
    null,
    "",
    location.pathname + location.search + "#" + nama,
  );
  window.scrollTo({ top: 0, behavior: "smooth" });
}

document.querySelectorAll("[data-bagian]").forEach((tombol) => {
  tombol.addEventListener("click", () => bukaBagian(tombol.dataset.bagian));
});

bukaBagian(location.hash.slice(1));

const jamLive = document.getElementById("jamLive");
const tglLive = document.getElementById("tglLive");

function perbaruiJam() {
  const sekarang = new Date();
  jamLive.textContent = sekarang
    .toLocaleTimeString("id-ID", {
      hour: "2-digit",
      minute: "2-digit",
      second: "2-digit",
    })
    .replace(/\./g, ":");
  tglLive.textContent = sekarang.toLocaleDateString("id-ID", {
    weekday: "long",
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

perbaruiJam();
setInterval(perbaruiJam, 1000);

const konfirmasi = document.getElementById("konfirmasi");
const teksKonfirmasi = document.getElementById("teksKonfirmasi");
let formTertunda = null;

function tutupKonfirmasi() {
  konfirmasi.classList.remove("tampil");
  formTertunda = null;
}

document.querySelectorAll("form[data-konfirm]").forEach((form) => {
  form.addEventListener("submit", (e) => {
    e.preventDefault();
    formTertunda = form;
    teksKonfirmasi.textContent = form.dataset.konfirm;
    konfirmasi.classList.add("tampil");
  });
});

document.getElementById("ya").addEventListener("click", () => {
  if (formTertunda) {
    formTertunda.submit();
  }
});
document.getElementById("batal").addEventListener("click", tutupKonfirmasi);
konfirmasi.addEventListener("click", (e) => {
  if (e.target === konfirmasi) {
    tutupKonfirmasi();
  }
});
document.addEventListener("keydown", (e) => {
  if (e.key === "Escape") {
    tutupKonfirmasi();
  }
});

const toast = document.getElementById("toast");
if (toast) {
  setTimeout(() => toast.remove(), 4300);
}

const cariSiswa = document.getElementById("cariSiswa");
const kartuSiswa = document.querySelectorAll(".kartu-siswa");
const kosongSiswa = document.getElementById("kosongSiswa");

if (cariSiswa) {
  cariSiswa.addEventListener("input", () => {
    const kata = cariSiswa.value.trim().toLowerCase();
    let terlihat = 0;
    kartuSiswa.forEach((kartu) => {
      const cocok = kartu.dataset.cari.includes(kata);
      kartu.classList.toggle("sembunyi", !cocok);
      if (cocok) {
        terlihat++;
      }
    });
    if (kosongSiswa) {
      kosongSiswa.classList.toggle("tampil", terlihat === 0);
    }
  });
}

const tglAbsen = document.getElementById("tglAbsen");
if (tglAbsen) {
  tglAbsen.addEventListener("change", () =>
    document.getElementById("formTgl").submit(),
  );
}

const semuaHadir = document.getElementById("semuaHadir");
if (semuaHadir) {
  semuaHadir.addEventListener("click", () => {
    document
      .querySelectorAll('.absen-baris input[value="H"]')
      .forEach((radio) => {
        radio.checked = true;
      });
  });
}
