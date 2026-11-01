window.onpageshow = function (event) {
  if (event.persisted) {
    window.location.reload();
  }
};

const infoBagian = {
  beranda: ["Beranda", "Ringkasan belajarmu hari ini"],
  tugas: ["Tugas", "Catat dan selesaikan tugasmu tepat waktu"],
  jadwal: ["Jadwal Pelajaran", "Atur pelajaran mingguanmu"],
  catatan: ["Catatan", "Simpan ide dan materi penting"],
  nilai: ["Nilai", "Pantau rata-rata nilaimu"],
  fokus: ["Mode Fokus", "Belajar tanpa gangguan dengan teknik Pomodoro"],
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
  history.replaceState(null, "", "#" + nama);
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

const tombolFilter = document.querySelectorAll("[data-filter]");
const itemTugas = document.querySelectorAll(".tugas-item");
const kosongFilter = document.getElementById("kosongFilter");

tombolFilter.forEach((tombol) => {
  tombol.addEventListener("click", () => {
    const filter = tombol.dataset.filter;
    tombolFilter.forEach((t) => t.classList.toggle("aktif", t === tombol));
    let terlihat = 0;
    itemTugas.forEach((item) => {
      const cocok = filter === "semua" || item.dataset.status === filter;
      item.classList.toggle("sembunyi", !cocok);
      if (cocok) {
        terlihat++;
      }
    });
    if (kosongFilter) {
      kosongFilter.classList.toggle("tampil", terlihat === 0);
    }
  });
});

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

const durasi = { fokus: 25, istirahat: 5, panjang: 15 };
const label = {
  fokus: "Waktunya fokus",
  istirahat: "Istirahat sebentar",
  panjang: "Istirahat panjang",
};
const KELILING = 565.49;

const waktuEl = document.getElementById("waktuFokus");
const labelEl = document.getElementById("labelFokus");
const ringMaju = document.getElementById("ringMaju");
const tombolMulai = document.getElementById("tombolMulai");
const tombolUlang = document.getElementById("tombolUlang");
const jumlahSesi = document.getElementById("jumlahSesi");
const tombolMode = document.querySelectorAll("[data-mode]");
const judulAsli = document.title;

let modeAktif = "fokus";
let total = durasi.fokus * 60;
let sisa = total;
let jalan = null;

const kunciSesi = "sesiFokus-" + new Date().toDateString();

function bacaSesi() {
  try {
    return parseInt(localStorage.getItem(kunciSesi) || "0", 10);
  } catch (e) {
    return 0;
  }
}

function tambahSesi() {
  try {
    localStorage.setItem(kunciSesi, String(bacaSesi() + 1));
  } catch (e) {}
  jumlahSesi.textContent = bacaSesi();
}

jumlahSesi.textContent = bacaSesi();

function tampilkan() {
  const menit = String(Math.floor(sisa / 60)).padStart(2, "0");
  const detik = String(sisa % 60).padStart(2, "0");
  waktuEl.textContent = menit + ":" + detik;
  ringMaju.style.strokeDashoffset = KELILING * (1 - sisa / total);
  document.title = jalan
    ? menit + ":" + detik + " - " + label[modeAktif]
    : judulAsli;
}

function bunyi() {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    [0, 0.3, 0.6].forEach((jeda) => {
      const osc = ctx.createOscillator();
      const gain = ctx.createGain();
      osc.connect(gain);
      gain.connect(ctx.destination);
      osc.frequency.value = 880;
      gain.gain.setValueAtTime(0.15, ctx.currentTime + jeda);
      gain.gain.exponentialRampToValueAtTime(
        0.001,
        ctx.currentTime + jeda + 0.22,
      );
      osc.start(ctx.currentTime + jeda);
      osc.stop(ctx.currentTime + jeda + 0.22);
    });
  } catch (e) {}
}

function berhenti() {
  clearInterval(jalan);
  jalan = null;
  tombolMulai.textContent = "Mulai";
}

function aturMode(nama) {
  berhenti();
  modeAktif = nama;
  total = durasi[nama] * 60;
  sisa = total;
  labelEl.textContent = label[nama];
  tombolMode.forEach((t) =>
    t.classList.toggle("aktif", t.dataset.mode === nama),
  );
  tampilkan();
}

function selesai() {
  berhenti();
  bunyi();
  if (modeAktif === "fokus") {
    tambahSesi();
    aturMode("istirahat");
  } else {
    aturMode("fokus");
  }
}

tombolMulai.addEventListener("click", () => {
  if (jalan) {
    berhenti();
    tampilkan();
    return;
  }
  tombolMulai.textContent = "Jeda";
  jalan = setInterval(() => {
    sisa--;
    tampilkan();
    if (sisa <= 0) {
      selesai();
    }
  }, 1000);
});

tombolUlang.addEventListener("click", () => aturMode(modeAktif));
tombolMode.forEach((t) =>
  t.addEventListener("click", () => aturMode(t.dataset.mode)),
);

tampilkan();
