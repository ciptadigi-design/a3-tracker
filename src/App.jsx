import React, { useState, useMemo, useEffect } from 'react';
import { AlertCircle, CheckCircle, Settings, Printer, PlusCircle, RotateCcw, BarChart3, Info, Lock, Unlock, Trash2, X, Pencil, Calendar, Filter, FileText, AlertTriangle, FileWarning, Eye, Sun, Moon, LayoutDashboard, DollarSign, Activity, TrendingUp, PieChart, Package, ShoppingCart, Minus } from 'lucide-react';

// --- KONEKSI SUPABASE VIA REST API ---
const supabaseUrl = 'https://wtslqxjwjqyjgcapfrrz.supabase.co';
const supabaseKey = 'sb_publishable_HyWB1TfQr-N514kXP3qVjA_uqAlox-A';

const supabaseHeaders = {
  'apikey': supabaseKey,
  'Authorization': `Bearer ${supabaseKey}`,
  'Content-Type': 'application/json'
};

const ADMIN_PASSWORD = 'admin'; 

// --- DAFTAR PART DARI CSV (DENGAN TAMBAHAN CMYK & OTHER PART) ---
const PREDEFINED_PARTS = [
  "Charging Corona Cyan",
  "Charging Corona Magenta",
  "Charging Corona Yellow",
  "Charging Corona Black",
  "Cleaning Blade",
  "Cleaning Unit",
  "Developer Cyan",
  "Developer Magenta",
  "Developer Yellow",
  "Developer Black",
  "Developing Unit Cyan",
  "Developing Unit Magenta",
  "Developing Unit Yellow",
  "Developing Unit Black",
  "Drum Unit Cyan",
  "Drum Unit Magenta",
  "Drum Unit Yellow",
  "Drum Unit Black",
  "Fuser Belt",
  "Gear",
  "Intermediate Transfer Belt (IBT)",
  "Laser Unit",
  "Roll Mesin",
  "Sensor",
  "Toner Cyan",
  "Toner Magenta",
  "Toner Yellow",
  "Toner Black",
  "Other Part"
];

// --- KONFIGURASI LIFETIME SUKU CADANG ---
const PART_LIFETIMES = {
  "Charging Corona Cyan": 40000,
  "Charging Corona Magenta": 40000,
  "Charging Corona Yellow": 40000,
  "Charging Corona Black": 40000,
  "Cleaning Blade": 100000,
  "Cleaning Unit": 200000,
  "Developer Cyan": 100000,
  "Developer Magenta": 100000,
  "Developer Yellow": 100000,
  "Developer Black": 100000,
  "Developing Unit Cyan": 200000,
  "Developing Unit Magenta": 200000,
  "Developing Unit Yellow": 200000,
  "Developing Unit Black": 200000,
  "Drum Unit Cyan": 40000,
  "Drum Unit Magenta": 40000,
  "Drum Unit Yellow": 40000,
  "Drum Unit Black": 40000,
  "Fuser Belt": 200000,
  "Gear": 150000,
  "Intermediate Transfer Belt (IBT)": 200000,
  "Laser Unit": 300000,
  "Roll Mesin": 150000,
  "Sensor": 250000,
  "Toner Cyan": 14000,
  "Toner Magenta": 14000,
  "Toner Yellow": 14000,
  "Toner Black": 14000
};

const getTodayStr = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
const getYesterdayStr = () => { const d = new Date(); d.setDate(d.getDate() - 1); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; };
const getNowDateTimeStr = () => { const d = new Date(); return `${getTodayStr()} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}:${String(d.getSeconds()).padStart(2, '0')}`; };
const formatDateToLocale = (isoString) => { if (!isoString) return '-'; const d = new Date(isoString); return `${d.getDate().toString().padStart(2, '0')}/${(d.getMonth() + 1).toString().padStart(2, '0')}/${d.getFullYear()}`; };

export default function App() {
  const [isDarkMode, setIsDarkMode] = useState(() => {
    if (typeof window !== 'undefined') {
      const savedTheme = localStorage.getItem('theme');
      if (savedTheme) return savedTheme === 'dark';
      return new Date().getHours() >= 12;
    }
    return false;
  });

  const toggleTheme = () => {
    const newMode = !isDarkMode;
    setIsDarkMode(newMode);
    localStorage.setItem('theme', newMode ? 'dark' : 'light');
  };

  const [clickPrice, setClickPrice] = useState(() => {
    if (typeof window !== 'undefined') {
      const savedPrice = localStorage.getItem('clickPrice');
      return savedPrice ? parseInt(savedPrice) : 1000;
    }
    return 1000;
  });
  const [adminPriceInput, setAdminPriceInput] = useState(clickPrice.toString());

  // --- APPLE STYLE SYSTEM (HEX CODES & GLASSMORPHISM) ---
  const cls = {
    appBg: isDarkMode ? 'bg-[#000000] text-[#F5F5F7]' : 'bg-[#F5F5F7] text-[#1D1D1F]',
    cardBg: isDarkMode ? 'bg-[#1C1C1E]/80 backdrop-blur-2xl border border-[#2C2C2E] shadow-[0_8px_30px_rgb(0,0,0,0.12)] rounded-[24px]' : 'bg-white/80 backdrop-blur-2xl border border-white/40 shadow-[0_8px_30px_rgb(0,0,0,0.04)] rounded-[24px]',
    cardHeader: isDarkMode ? 'bg-[#2C2C2E]/40 border-[#38383A]' : 'bg-[#F5F5F7]/50 border-[#E5E5EA]',
    textMain: isDarkMode ? 'text-[#F5F5F7]' : 'text-[#1D1D1F]',
    textSub: isDarkMode ? 'text-[#98989D]' : 'text-[#86868B]',
    textMuted: isDarkMode ? 'text-[#636366]' : 'text-[#AEAEB2]',
    
    input: isDarkMode ? 'bg-[#2C2C2E] border border-[#38383A] text-white [color-scheme:dark] focus:ring-[3px] focus:ring-[#0A84FF]/40 focus:border-[#0A84FF] rounded-[14px] transition-all' : 'bg-white border border-[#E5E5EA] text-[#1D1D1F] [color-scheme:light] focus:ring-[3px] focus:ring-[#007AFF]/30 focus:border-[#007AFF] rounded-[14px] transition-all shadow-sm',
    inputDisabled: isDarkMode ? 'bg-[#1C1C1E] border-[#2C2C2E] text-[#636366] opacity-70' : 'bg-[#F5F5F7] border-[#E5E5EA] text-[#AEAEB2] opacity-70',
    btnSec: isDarkMode ? 'bg-[#2C2C2E] hover:bg-[#3A3A3C] text-white border border-[#38383A] rounded-full transition-all' : 'bg-white hover:bg-[#E5E5EA] text-[#1D1D1F] border border-[#D1D1D6] shadow-sm rounded-full transition-all',

    tabContainer: isDarkMode ? 'bg-[#1C1C1E] p-1 rounded-[16px] border border-[#2C2C2E]' : 'bg-[#E3E3E8]/70 p-1 rounded-[16px]',
    tabAct: isDarkMode ? 'bg-[#3A3A3C] text-white shadow-sm rounded-[12px] font-semibold' : 'bg-white text-[#1D1D1F] shadow-sm rounded-[12px] font-semibold',
    tabInact: isDarkMode ? 'text-[#98989D] hover:text-[#F5F5F7]' : 'text-[#86868B] hover:text-[#1D1D1F]',

    indigoText: isDarkMode ? 'text-[#0A84FF]' : 'text-[#007AFF]',
    indigoBg: isDarkMode ? 'bg-[#0A84FF]' : 'bg-[#007AFF]',
    indigoIcon: isDarkMode ? 'bg-[#0A84FF]/20 text-[#0A84FF]' : 'bg-[#007AFF]/10 text-[#007AFF]',

    roseText: isDarkMode ? 'text-[#FF453A]' : 'text-[#FF3B30]',
    roseBg: isDarkMode ? 'bg-[#FF453A]' : 'bg-[#FF3B30]',
    roseIcon: isDarkMode ? 'bg-[#FF453A]/20 text-[#FF453A]' : 'bg-[#FF3B30]/10 text-[#FF3B30]',

    amberText: isDarkMode ? 'text-[#FF9F0A]' : 'text-[#FF9500]',
    amberBg: isDarkMode ? 'bg-[#FF9F0A]' : 'bg-[#FF9500]',
    amberIcon: isDarkMode ? 'bg-[#FF9F0A]/20 text-[#FF9F0A]' : 'bg-[#FF9500]/10 text-[#FF9500]',

    emeraldText: isDarkMode ? 'text-[#32D74B]' : 'text-[#34C759]',
    emeraldBg: isDarkMode ? 'bg-[#32D74B]' : 'bg-[#34C759]',
    emeraldIcon: isDarkMode ? 'bg-[#32D74B]/20 text-[#32D74B]' : 'bg-[#34C759]/10 text-[#34C759]',

    tableHead: isDarkMode ? 'bg-[#1C1C1E]/90 backdrop-blur-md border-[#38383A] text-[#98989D]' : 'bg-[#F5F5F7]/90 backdrop-blur-md border-[#E5E5EA] text-[#86868B]',
    tableRow: isDarkMode ? 'hover:bg-[#2C2C2E]/40' : 'hover:bg-[#F5F5F7]/60',
    tableDiv: isDarkMode ? 'divide-[#38383A]' : 'divide-[#E5E5EA]',
    modalBg: isDarkMode ? 'bg-black/60 backdrop-blur-md' : 'bg-black/30 backdrop-blur-sm',

    chartTooltip: isDarkMode ? 'bg-[#2C2C2E] text-white shadow-xl border border-[#38383A] rounded-[14px]' : 'bg-white text-[#1D1D1F] shadow-xl border border-[#E5E5EA] rounded-[14px]',
    chartAxisLine: isDarkMode ? 'border-[#38383A]' : 'border-[#E5E5EA]',
    lockOverlay: isDarkMode ? 'bg-[#000000]/60 text-[#F5F5F7] backdrop-blur-sm rounded-[24px]' : 'bg-white/60 text-[#1D1D1F] backdrop-blur-sm rounded-[24px]',
  };

  const [history, setHistory] = useState([]);
  const [replacementHistory, setReplacementHistory] = useState([]);
  const [errorLogs, setErrorLogs] = useState([]);
  
  const [inventory, setInventory] = useState([]);
  const [purchases, setPurchases] = useState([]);
  const [replacements, setReplacements] = useState({});
  const [isLoading, setIsLoading] = useState(true);

  const [operator, setOperator] = useState('');
  const [newClicks, setNewClicks] = useState('');
  const [dateType, setDateType] = useState('Hari Ini');

  const [replacePart, setReplacePart] = useState('');
  const [replaceOperator, setReplaceOperator] = useState('');
  const [replaceClick, setReplaceClick] = useState(''); 

  const [errorForm, setErrorForm] = useState({
    tgl: getTodayStr(), nomor_invoice: '', divisi: 'CG', nama_konsumen: '', nama_produk: '',
    qty_kesalahan: '', kerugian_bahan: '', kerugian_jasa: '', kategori_kesalahan: 'Kesesuaian/Ketepatan',
    jenis_kesalahan: 'Machine Error', deskripsi_kesalahan: '', penyebab: '', pencegahan_solusi: '',
    penyelesaian: '', pic: '' // default kosong (Penyelesaian Untuk Konsumen)
  });

  const [purchaseForm, setPurchaseForm] = useState({
    tgl_pembelian: getTodayStr(), part_name: '', qty: '', harga_satuan: '', supplier: ''
  });

  const [activeTab, setActiveTab] = useState('dashboard');
  const [reportPeriod, setReportPeriod] = useState('week'); 
  const [customStart, setCustomStart] = useState('');
  const [customEnd, setCustomEnd] = useState('');

  const [isAdmin, setIsAdmin] = useState(false);
  const [showLoginModal, setShowLoginModal] = useState(false);
  const [adminPwdInput, setAdminPwdInput] = useState('');

  const [deleteModal, setDeleteModal] = useState({ isOpen: false, item: null, type: '' });
  const [editModal, setEditModal] = useState({ isOpen: false, item: null });
  const [editFormClick, setEditFormClick] = useState({ dateFor: '', operator: '', totalClicks: '' });

  const [errorDetailModal, setErrorDetailModal] = useState({ isOpen: false, item: null });
  const [editErrorModal, setEditErrorModal] = useState({ isOpen: false, item: null });
  const [editErrorForm, setEditErrorForm] = useState({ ...errorForm });

  const [editPurchaseModal, setEditPurchaseModal] = useState({ isOpen: false, item: null });
  const [editPurchaseForm, setEditPurchaseForm] = useState({ tgl_pembelian: '', part_name: '', qty: '', harga_satuan: '', supplier: '' });

  const [editInventoryModal, setEditInventoryModal] = useState({ isOpen: false, item: null });
  const [editInventoryForm, setEditInventoryForm] = useState({ part_name: '', stock: '' });

  const [toast, setToast] = useState(null);

  useEffect(() => {
    fetchData();
  }, []);

  useEffect(() => {
    if (typeof window !== 'undefined') {
      const root = window.document.documentElement;
      if (isDarkMode) {
        root.style.colorScheme = 'dark';
      } else {
        root.style.colorScheme = 'light';
      }
    }
  }, [isDarkMode]);

  const fetchData = async () => {
    setIsLoading(true);
    try {
      const historyRes = fetch(`${supabaseUrl}/rest/v1/click_history?select=*&order=total_clicks.asc`, { headers: supabaseHeaders });
      const partRes = fetch(`${supabaseUrl}/rest/v1/part_replacements?select=*&order=replaced_at_click.asc`, { headers: supabaseHeaders });
      const errorRes = fetch(`${supabaseUrl}/rest/v1/error_logs?select=*&order=tgl.desc`, { headers: supabaseHeaders });
      const invRes = fetch(`${supabaseUrl}/rest/v1/inventory_parts?select=*&order=part_name.asc`, { headers: supabaseHeaders });
      const purRes = fetch(`${supabaseUrl}/rest/v1/part_purchases?select=*&order=tgl_pembelian.desc`, { headers: supabaseHeaders });

      const [resH, resP, resE, resInv, resPur] = await Promise.all([historyRes, partRes, errorRes, invRes, purRes]);

      if (resH.ok) {
        const data = await resH.json();
        setHistory(data.map(item => ({ id: item.id, dateStr: item.date_str, dateFor: item.date_for, operator: item.operator, totalClicks: item.total_clicks, dailyClicks: item.daily_clicks })));
      }

      if (resP.ok) {
        const data = await resP.json();
        setReplacementHistory(data.map(item => ({ id: item.id, partName: item.part_name, replacedAtClick: item.replaced_at_click, operator: item.operator, createdAt: item.created_at })));
        const repl = {};
        Object.keys(PART_LIFETIMES).forEach(p => repl[p] = 0);
        data.forEach(item => { if (!repl[item.part_name] || item.replaced_at_click > repl[item.part_name]) repl[item.part_name] = item.replaced_at_click; });
        setReplacements(repl);
      }

      if (resE.ok) setErrorLogs(await resE.json());
      if (resInv.ok) setInventory(await resInv.json());
      if (resPur.ok) setPurchases(await resPur.json());

    } catch (err) {
      showToast("Koneksi ke Supabase gagal.", "error");
    } finally {
      setIsLoading(false);
    }
  };

  const sortedHistory = useMemo(() => [...history].sort((a, b) => a.totalClicks - b.totalClicks), [history]);
  const currentTotalClicks = sortedHistory.length > 0 ? sortedHistory[sortedHistory.length - 1].totalClicks : 0;

  const currentMultiplier = useMemo(() => {
    if (!errorForm.pic || !errorForm.deskripsi_kesalahan) return 1;
    if (errorForm.jenis_kesalahan === 'Print Test') return 1;
    const picLower = errorForm.pic.toLowerCase().trim();
    const deskripsiLower = errorForm.deskripsi_kesalahan.toLowerCase().trim();
    if (deskripsiLower === '') return 1;
    const previousErrors = errorLogs.filter(e => e.pic && e.pic.toLowerCase().trim() === picLower && e.deskripsi_kesalahan && e.deskripsi_kesalahan.toLowerCase().trim() === deskripsiLower && e.jenis_kesalahan !== 'Print Test');
    return previousErrors.length + 1;
  }, [errorForm.pic, errorForm.deskripsi_kesalahan, errorForm.jenis_kesalahan, errorLogs]);

  const showToast = (message, type = 'success') => {
    setToast({ message, type });
    setTimeout(() => setToast(null), 3000);
  };

  const handleAdminLogin = (e) => {
    e.preventDefault();
    if (adminPwdInput === ADMIN_PASSWORD) { setIsAdmin(true); setShowLoginModal(false); setAdminPwdInput(''); showToast("Berhasil masuk Mode Admin"); } 
    else { showToast("Password Admin Salah!", "error"); }
  };

  const handleUpdatePrice = (e) => {
    e.preventDefault(); const newPrice = parseInt(adminPriceInput);
    if (!isNaN(newPrice) && newPrice >= 0) { setClickPrice(newPrice); localStorage.setItem('clickPrice', newPrice.toString()); showToast("Harga per klik berhasil diperbarui!"); } 
    else { showToast("Masukkan nominal harga yang valid!", "error"); }
  };

  const confirmDelete = (item, type) => { setDeleteModal({ isOpen: true, item, type }); };
  const executeDelete = async () => {
    const { item, type } = deleteModal;
    let endpoint = '';
    if (type === 'click') endpoint = 'click_history';
    else if (type === 'part') endpoint = 'part_replacements';
    else if (type === 'error') endpoint = 'error_logs';
    else if (type === 'purchase') endpoint = 'part_purchases';
    else if (type === 'inventory') endpoint = 'inventory_parts';

    try {
      const response = await fetch(`${supabaseUrl}/rest/v1/${endpoint}?id=eq.${item.id}`, { method: 'DELETE', headers: supabaseHeaders });
      if (!response.ok) throw new Error("Gagal menghapus data");

      if (type === 'click') setHistory(history.filter(h => h.id !== item.id));
      else if (type === 'part') {
        const newHist = replacementHistory.filter(h => h.id !== item.id); setReplacementHistory(newHist);
        const repl = {}; Object.keys(PART_LIFETIMES).forEach(p => repl[p] = 0); newHist.forEach(h => { if (!repl[h.partName] || h.replacedAtClick > repl[h.partName]) repl[h.partName] = h.replacedAtClick; }); setReplacements(repl);
      }
      else if (type === 'error') setErrorLogs(errorLogs.filter(e => e.id !== item.id));
      else if (type === 'purchase') setPurchases(purchases.filter(p => p.id !== item.id));
      else if (type === 'inventory') setInventory(inventory.filter(i => i.id !== item.id));

      showToast("Data berhasil dihapus!");
    } catch (err) { showToast("Gagal menghapus dari database cloud.", "error"); } 
    finally { setDeleteModal({ isOpen: false, item: null, type: '' }); }
  };

  const handleDecreaseStock = async (item) => {
    if (item.stock <= 0) return showToast("Stok sudah habis!", "error");
    const newStock = item.stock - 1;
    try {
      const res = await fetch(`${supabaseUrl}/rest/v1/inventory_parts?id=eq.${item.id}`, {
        method: 'PATCH', headers: { ...supabaseHeaders, 'Prefer': 'return=representation' },
        body: JSON.stringify({ stock: newStock })
      });
      if (!res.ok) throw new Error("Gagal");
      const updated = await res.json();
      setInventory(inventory.map(inv => inv.id === item.id ? updated[0] : inv));
      showToast(`1 Unit ${item.part_name} berhasil digunakan.`);
    } catch (err) {
      showToast("Gagal mengurangi stok ke database.", "error");
    }
  };

  const handleSavePurchase = async (e) => {
    e.preventDefault();
    if (!purchaseForm.part_name || !purchaseForm.qty || !purchaseForm.harga_satuan) { return showToast("Lengkapi form pembelian!", "error"); }
    const qtyInt = parseInt(purchaseForm.qty); const hargaInt = parseFloat(purchaseForm.harga_satuan); const totalHarga = qtyInt * hargaInt;
    const payloadPurchase = { tgl_pembelian: purchaseForm.tgl_pembelian, part_name: purchaseForm.part_name, qty: qtyInt, harga_satuan: hargaInt, total_harga: totalHarga, supplier: purchaseForm.supplier || '' };

    try {
      const resPur = await fetch(`${supabaseUrl}/rest/v1/part_purchases`, { method: 'POST', headers: { ...supabaseHeaders, 'Prefer': 'return=representation' }, body: JSON.stringify(payloadPurchase) });
      if (!resPur.ok) throw new Error("Gagal simpan histori");
      const savedPurchase = await resPur.json(); setPurchases([savedPurchase[0], ...purchases]);

      const existingPart = inventory.find(p => p.part_name.toLowerCase() === purchaseForm.part_name.toLowerCase());
      if (existingPart) {
        const resInv = await fetch(`${supabaseUrl}/rest/v1/inventory_parts?id=eq.${existingPart.id}`, { method: 'PATCH', headers: { ...supabaseHeaders, 'Prefer': 'return=representation' }, body: JSON.stringify({ stock: existingPart.stock + qtyInt }) });
        if(resInv.ok) { const updatedInv = await resInv.json(); setInventory(inventory.map(item => item.id === existingPart.id ? updatedInv[0] : item)); }
      } else {
        const resInv = await fetch(`${supabaseUrl}/rest/v1/inventory_parts`, { method: 'POST', headers: { ...supabaseHeaders, 'Prefer': 'return=representation' }, body: JSON.stringify({ part_name: purchaseForm.part_name, stock: qtyInt }) });
        if(resInv.ok) { const newInv = await resInv.json(); setInventory([...inventory, newInv[0]]); }
      }
      showToast(`Pembelian ${purchaseForm.part_name} berhasil dicatat & stok bertambah!`);
      setPurchaseForm({ tgl_pembelian: purchaseForm.tgl_pembelian, part_name: '', qty: '', harga_satuan: '', supplier: purchaseForm.supplier });
    } catch(err) { showToast("Gagal memproses pembelian.", "error"); }
  };

  const handleSaveData = async (e) => {
    e.preventDefault();
    if (!operator || !newClicks || isNaN(newClicks)) return showToast("Lengkapi form dengan benar!", "error");
    const clickInt = parseInt(newClicks); if (clickInt <= currentTotalClicks && history.length > 0) return showToast(`Klik harus > ${currentTotalClicks}`, "error");
    const targetDateFor = dateType === 'Kemarin' ? getYesterdayStr() : getTodayStr(); let clicksBeforeTarget = 0;
    const historyByDate = [...history].sort((a, b) => new Date(a.dateFor) - new Date(b.dateFor));
    for (let i = historyByDate.length - 1; i >= 0; i--) { if (new Date(historyByDate[i].dateFor) < new Date(targetDateFor)) { clicksBeforeTarget = historyByDate[i].totalClicks; break; } }
    if (clicksBeforeTarget === 0 && historyByDate.length > 0) clicksBeforeTarget = historyByDate[0].totalClicks;
    let dailyC = history.length === 0 ? 0 : clickInt - clicksBeforeTarget;
    const newEntryDb = { date_str: getNowDateTimeStr(), date_for: targetDateFor, operator, total_clicks: clickInt, daily_clicks: dailyC > 0 ? dailyC : 0 };
    try {
      const res = await fetch(`${supabaseUrl}/rest/v1/click_history`, { method: 'POST', headers: { ...supabaseHeaders, 'Prefer': 'return=representation' }, body: JSON.stringify(newEntryDb) });
      if (!res.ok) throw new Error("Error db");
      const data = await res.json(); const ins = data && data.length > 0 ? data[0] : newEntryDb;
      setHistory([...history, { id: ins.id, dateStr: ins.date_str, dateFor: ins.date_for, operator: ins.operator, totalClicks: ins.total_clicks, dailyClicks: ins.daily_clicks }]);
      setOperator(''); setNewClicks(''); setDateType('Hari Ini'); showToast(`Data tersimpan!`);
    } catch (err) { showToast("Gagal menyimpan data klik", "error"); }
  };

  // --- LOGIKA UTAMA RESET PART: RESETS LIFETIME & AUTO-POTONG 1 UNIT STOK GUDANG ---
  const handleReplacePart = async (e) => {
    e.preventDefault();
    if (!isAdmin) return showToast("Hanya Admin yang bisa reset part!", "error");
    if (!replacePart) return showToast("Pilih part!", "error");
    if (!replaceOperator) return showToast("Nama teknisi wajib!", "error");

    const targetClick = replaceClick !== '' ? parseInt(replaceClick) : currentTotalClicks;
    if (isNaN(targetClick)) return showToast("Klik mesin tidak valid!", "error");

    // 1. Validasi ganda ketersediaan stok fisik di gudang sebelum tindakan reset
    const existingPartInInv = inventory.find(p => p.part_name.toLowerCase() === replacePart.toLowerCase());
    if (!existingPartInInv || existingPartInInv.stock <= 0) {
      return showToast(`Stok ${replacePart} tidak mencukupi di gudang! Silakan beli dulu.`, "error");
    }

    try {
      // 2. Post record penggantian baru ke Supabase
      const response = await fetch(`${supabaseUrl}/rest/v1/part_replacements`, {
        method: 'POST',
        headers: { ...supabaseHeaders, 'Prefer': 'return=representation' },
        body: JSON.stringify({ part_name: replacePart, replaced_at_click: targetClick, operator: replaceOperator })
      });
      if (!response.ok) throw new Error("Gagal reset");
      const data = await response.json();

      const insertedData = data && data.length > 0 ? {
        id: data[0].id,
        partName: data[0].part_name,
        replacedAtClick: data[0].replaced_at_click,
        operator: data[0].operator,
        createdAt: data[0].created_at
      } : {
        id: Date.now(), partName: replacePart, replacedAtClick: targetClick, operator: replaceOperator, createdAt: new Date().toISOString()
      };

      setReplacementHistory([...replacementHistory, insertedData]);
      setReplacements({ ...replacements, [replacePart]: targetClick });

      // 3. Potong stok otomatis sebanyak 1 unit di Supabase
      const newStock = existingPartInInv.stock - 1;
      const resInv = await fetch(`${supabaseUrl}/rest/v1/inventory_parts?id=eq.${existingPartInInv.id}`, {
        method: 'PATCH',
        headers: { ...supabaseHeaders, 'Prefer': 'return=representation' },
        body: JSON.stringify({ stock: newStock })
      });
      
      if (resInv.ok) {
        const updatedInv = await resInv.json();
        setInventory(inventory.map(inv => inv.id === existingPartInInv.id ? updatedInv[0] : inv));
      }

      setReplacePart('');
      setReplaceOperator('');
      setReplaceClick('');
      showToast(`Reset part ${replacePart} sukses & stok otomatis dikurangi 1!`);
    } catch (err) {
      showToast("Gagal memproses reset suku cadang.", "error");
    }
  };

  const handleSaveErrorLog = async (e) => {
    e.preventDefault();
    if (!errorForm.nama_konsumen || !errorForm.nama_produk || !errorForm.pic) return showToast("Wajib diisi!", "error");
    const kb = parseFloat(errorForm.kerugian_bahan) || 0; const kj = parseFloat(errorForm.kerugian_jasa) || 0;
    const totalKerugian = (kb + kj) * currentMultiplier;
    
    // Punishment status mapping
    const finalPenyelesaian = currentMultiplier > 1 
      ? `${errorForm.penyelesaian} [PUNISHMENT ${currentMultiplier}X LIPAT]` 
      : errorForm.penyelesaian;

    try {
      const res = await fetch(`${supabaseUrl}/rest/v1/error_logs`, { method: 'POST', headers: { ...supabaseHeaders, 'Prefer': 'return=representation' }, body: JSON.stringify({ ...errorForm, qty_kesalahan: parseInt(errorForm.qty_kesalahan) || 0, kerugian_bahan: kb, kerugian_jasa: kj, jumlah_kerugian: totalKerugian, penyelesaian: finalPenyelesaian }) });
      if (!res.ok) throw new Error("Gagal simpan");
      const data = await res.json(); setErrorLogs([data[0], ...errorLogs]);
      showToast(currentMultiplier > 1 ? `Log dicatat! Punishment ${currentMultiplier}x.` : "Log Error dicatat!");
      setErrorForm({ ...errorForm, nomor_invoice: '', nama_konsumen: '', nama_produk: '', qty_kesalahan: '', kerugian_bahan: '', kerugian_jasa: '', deskripsi_kesalahan: '', penyebab: '', pencegahan_solusi: '', penyelesaian: '', pic: '' });
    } catch (err) { showToast("Gagal menyimpan error", "error"); }
  };

  const confirmEditClick = (item) => { setEditFormClick({ dateFor: item.dateFor, operator: item.operator, totalClicks: item.totalClicks }); setEditModal({ isOpen: true, item }); };
  const executeEditClick = async (e) => {
    e.preventDefault(); const { item } = editModal; const newTotal = parseInt(editFormClick.totalClicks);
    let clicksBeforeTarget = 0; const historyByDate = [...history].filter(h => h.id !== item.id).sort((a, b) => new Date(a.dateFor) - new Date(b.dateFor));
    for (let i = historyByDate.length - 1; i >= 0; i--) { if (new Date(historyByDate[i].dateFor) < new Date(editFormClick.dateFor)) { clicksBeforeTarget = historyByDate[i].totalClicks; break; } }
    if (clicksBeforeTarget === 0 && historyByDate.length > 0) clicksBeforeTarget = historyByDate[0].totalClicks;
    let newDaily = newTotal - clicksBeforeTarget; if (history.length <= 1) newDaily = 0;
    try {
      const response = await fetch(`${supabaseUrl}/rest/v1/click_history?id=eq.${item.id}`, { method: 'PATCH', headers: { ...supabaseHeaders, 'Prefer': 'return=representation' }, body: JSON.stringify({ date_for: editFormClick.dateFor, operator: editFormClick.operator, total_clicks: newTotal, daily_clicks: newDaily > 0 ? newDaily : 0 }) });
      if (!response.ok) throw new Error("Gagal update"); const data = await response.json();
      if (data && data.length > 0) { const d = data[0]; setHistory(history.map(h => h.id === item.id ? { ...h, dateFor: d.date_for, operator: d.operator, totalClicks: d.total_clicks, dailyClicks: d.daily_clicks } : h)); }
      showToast("Data berhasil diupdate!");
    } catch (err) { showToast("Gagal update", "error"); } finally { setEditModal({ isOpen: false, item: null }); }
  };

  const confirmEditError = (item) => { setEditErrorForm({ ...item }); setEditErrorModal({ isOpen: true, item }); };
  const executeEditError = async (e) => {
    e.preventDefault(); const { item } = editErrorModal;
    const kb = parseFloat(editErrorForm.kerugian_bahan) || 0; const kj = parseFloat(editErrorForm.kerugian_jasa) || 0;
    let mult = 1; if (editErrorForm.jenis_kesalahan !== 'Print Test') {
      const picLower = editErrorForm.pic.toLowerCase().trim(); const deskripsiLower = editErrorForm.deskripsi_kesalahan.toLowerCase().trim();
      if (deskripsiLower !== '') { const previousErrors = errorLogs.filter(err => err.id !== item.id && err.pic && err.pic.toLowerCase().trim() === picLower && err.deskripsi_kesalahan && err.deskripsi_kesalahan.toLowerCase().trim() === deskripsiLower && err.jenis_kesalahan !== 'Print Test'); mult = previousErrors.length + 1; }
    }
    const totalKerugian = (kb + kj) * mult;
    const finalPenyelesaian = mult > 1 && !editErrorForm.penyelesaian.includes('PUNISHMENT') ? `${editErrorForm.penyelesaian} [PUNISHMENT ${mult}X LIPAT]` : editErrorForm.penyelesaian;
    try {
      const response = await fetch(`${supabaseUrl}/rest/v1/error_logs?id=eq.${item.id}`, { method: 'PATCH', headers: { ...supabaseHeaders, 'Prefer': 'return=representation' }, body: JSON.stringify({ ...editErrorForm, qty_kesalahan: parseInt(editErrorForm.qty_kesalahan) || 0, kerugian_bahan: kb, kerugian_jasa: kj, jumlah_kerugian: totalKerugian, penyelesaian: finalPenyelesaian }) });
      if (!response.ok) throw new Error("Gagal update"); const data = await response.json();
      if (data && data.length > 0) setErrorLogs(errorLogs.map(err => err.id === item.id ? data[0] : err));
      showToast("Log Error diupdate!");
    } catch (err) { showToast("Gagal update", "error"); } finally { setEditErrorModal({ isOpen: false, item: null }); }
  };

  const confirmEditPurchase = (item) => { setEditPurchaseForm({ ...item }); setEditPurchaseModal({ isOpen: true, item }); };
  const executeEditPurchase = async (e) => {
    e.preventDefault(); const { item } = editPurchaseModal;
    const qtyInt = parseInt(editPurchaseForm.qty); const hargaInt = parseFloat(editPurchaseForm.harga_satuan); const totalHarga = qtyInt * hargaInt;
    const payload = { tgl_pembelian: editPurchaseForm.tgl_pembelian, part_name: editPurchaseForm.part_name, qty: qtyInt, harga_satuan: hargaInt, total_harga: totalHarga, supplier: editPurchaseForm.supplier };
    try {
      const res = await fetch(`${supabaseUrl}/rest/v1/part_purchases?id=eq.${item.id}`, { method: 'PATCH', headers: { ...supabaseHeaders, 'Prefer': 'return=representation' }, body: JSON.stringify(payload) });
      if (!res.ok) throw new Error("Gagal"); const data = await res.json();
      setPurchases(purchases.map(p => p.id === item.id ? data[0] : p));
      showToast("Data pembelian berhasil diupdate!");
    } catch (err) { showToast("Gagal update pembelian", "error"); } finally { setEditPurchaseModal({ isOpen: false, item: null }); }
  };

  const confirmEditInventory = (item) => { setEditInventoryForm({ part_name: item.part_name, stock: item.stock }); setEditInventoryModal({ isOpen: true, item }); };
  const executeEditInventory = async (e) => {
    e.preventDefault(); const { item } = editInventoryModal; const stockInt = parseInt(editInventoryForm.stock);
    try {
      const res = await fetch(`${supabaseUrl}/rest/v1/inventory_parts?id=eq.${item.id}`, { method: 'PATCH', headers: { ...supabaseHeaders, 'Prefer': 'return=representation' }, body: JSON.stringify({ part_name: editInventoryForm.part_name, stock: stockInt }) });
      if (!res.ok) throw new Error("Gagal"); const data = await res.json();
      setInventory(inventory.map(inv => inv.id === item.id ? data[0] : inv));
      showToast("Data stok gudang berhasil diupdate!");
    } catch (err) { showToast("Gagal update stok", "error"); } finally { setEditInventoryModal({ isOpen: false, item: null }); }
  };

  const filteredData = (dataArray, dateField) => {
    const today = new Date(); today.setHours(0, 0, 0, 0);
    return dataArray.filter(item => {
      if (!item[dateField]) return true;
      const itemDate = new Date(item[dateField]); itemDate.setHours(0, 0, 0, 0);
      if (reportPeriod === 'today') return itemDate.getTime() === today.getTime();
      if (reportPeriod === 'week') { const lw = new Date(today); lw.setDate(today.getDate() - 7); return itemDate >= lw && itemDate <= today; }
      if (reportPeriod === 'month') return itemDate.getMonth() === today.getMonth() && itemDate.getFullYear() === today.getFullYear();
      if (reportPeriod === 'custom') {
        if (!customStart || !customEnd) return true;
        const st = new Date(customStart); st.setHours(0, 0, 0, 0); const ed = new Date(customEnd); ed.setHours(23, 59, 59, 999); return itemDate >= st && itemDate <= ed;
      }
      return true;
    });
  };

  const reportClicks = filteredData(sortedHistory, 'dateFor').reverse();
  const totalReportUsage = reportClicks.reduce((sum, item) => sum + item.dailyClicks, 0);
  const reportReplacements = filteredData(replacementHistory, 'createdAt').reverse();
  const reportErrors = filteredData(errorLogs, 'tgl');
  const reportPurchases = filteredData(purchases, 'tgl_pembelian');

  const totalGrossRevenue = totalReportUsage * clickPrice;
  const totalRupiahKerugian = reportErrors.reduce((sum, item) => sum + Number(item.jumlah_kerugian), 0);
  const totalExpensePart = reportPurchases.reduce((sum, item) => sum + Number(item.total_harga), 0);
  const netMargin = totalGrossRevenue - totalExpensePart - totalRupiahKerugian;

  const clickPerPcsValue = totalReportUsage > 0 ? (totalExpensePart / totalReportUsage) : 0;

  // --- MEMBENTUK DAFTAR PART YANG READY DI GUDANG ---
  const availablePartsInWarehouse = useMemo(() => {
    return inventory
      // Pastikan stok > 0 DAN part tersebut ada di daftar PART_LIFETIMES (Other Part akan diabaikan dari menu Reset)
      .filter(item => item.stock > 0 && PART_LIFETIMES.hasOwnProperty(item.part_name))
      .map(item => item.part_name);
  }, [inventory]);

  const partData = Object.keys(PART_LIFETIMES).map(partName => {
    const lifetime = PART_LIFETIMES[partName]; const usage = Math.max(0, currentTotalClicks - (replacements[partName] || 0)); const remainingPercent = Math.max(0, ((lifetime - usage) / lifetime) * 100);
    let color = 'bg-[#32D74B]'; if (remainingPercent <= 10) color = 'bg-[#FF453A]'; else if (remainingPercent <= 25) color = 'bg-[#FF9F0A]';
    return { name: partName, usage, remainingPercent: remainingPercent.toFixed(1), estimatedReplace: (replacements[partName] || 0) + lifetime, color };
  });

  const clickChartData = useMemo(() => {
    const grouped = {}; const ascendingClicks = [...reportClicks].sort((a, b) => new Date(a.dateFor) - new Date(b.dateFor));
    ascendingClicks.forEach(item => { const date = item.dateFor; if (!grouped[date]) grouped[date] = 0; grouped[date] += item.dailyClicks; });
    let chartArray = Object.keys(grouped).map(date => {
      const d = new Date(date); const shortDate = `${d.getDate().toString().padStart(2, '0')}/${(d.getMonth() + 1).toString().padStart(2, '0')}`;
      return { fullDate: date, shortDate: shortDate, total: grouped[date] };
    });
    if (chartArray.length > 14) chartArray = chartArray.slice(chartArray.length - 14); 
    return chartArray;
  }, [reportClicks]);

  const maxClickChart = clickChartData.length > 0 ? Math.max(...clickChartData.map(d => d.total)) : 0;

  const errorChartData = useMemo(() => {
    const grouped = {};
    reportErrors.forEach(item => { const type = item.jenis_kesalahan || 'Lainnya'; if (!grouped[type]) grouped[type] = 0; grouped[type] += Number(item.jumlah_kerugian); });
    const colors = { 'Machine Error': 'bg-[#0A84FF]', 'Human Error': 'bg-[#FF453A]', 'Print Test': 'bg-[#FF9F0A]', 'Lainnya': 'bg-[#8E8E93]' };
    return Object.keys(grouped).map(type => ({ name: type, value: grouped[type], color: colors[type] || 'bg-[#0A84FF]' })).sort((a, b) => b.value - a.value); 
  }, [reportErrors]);

  if (isLoading) return <div className={`min-h-screen ${cls.appBg} flex items-center justify-center`}><div className="animate-spin rounded-full h-12 w-12 border-[3px] border-t-transparent border-[#007AFF]"></div></div>;

  return (
    <div className={`min-h-screen ${cls.appBg} font-sans antialiased tracking-tight p-4 md:p-8 relative pb-20 transition-colors duration-300`}>

      {/* Windows CSS Compatibility Fix */}
      <style>{`
        select option {
          background-color: ${isDarkMode ? '#1C1C1E' : '#FFFFFF'} !important;
          color: ${isDarkMode ? '#F5F5F7' : '#1D1D1F'} !important;
        }
        input[type="date"] {
          cursor: pointer;
        }
      `}</style>

      {/* --- SEMUA MODAL --- */}
      {deleteModal.isOpen && (
        <div className={`fixed inset-0 ${cls.modalBg} z-50 flex items-center justify-center p-4 transition-opacity`}>
          <div className={`${cls.cardBg} w-full max-w-sm p-6 text-center transform scale-100 transition-transform`}>
            <div className={`mx-auto w-14 h-14 ${cls.roseIcon} rounded-[18px] flex items-center justify-center mb-5`}><AlertCircle className="w-7 h-7" /></div>
            <h3 className={`text-xl font-semibold ${cls.textMain} mb-2`}>Hapus Data?</h3>
            <p className={`${cls.textSub} text-sm mb-6`}>Tindakan ini tidak bisa dibatalkan.</p>
            <div className="flex space-x-3">
              <button onClick={() => setDeleteModal({ isOpen: false, item: null, type: '' })} className={`flex-1 py-3 border ${cls.btnSec} font-semibold`}>Batal</button>
              <button onClick={executeDelete} className={`flex-1 py-3 ${cls.roseBg} text-white rounded-full font-semibold`}>Hapus</button>
            </div>
          </div>
        </div>
      )}

      {editModal.isOpen && (
        <div className={`fixed inset-0 ${cls.modalBg} z-50 flex items-center justify-center p-4`}>
          <div className={`${cls.cardBg} w-full max-w-md p-6 relative`}>
            <button onClick={() => setEditModal({ isOpen: false, item: null })} className={`absolute top-4 right-4 ${cls.textMuted} p-1 rounded-full hover:bg-black/5 dark:hover:bg-white/10`}><X className="w-5 h-5" /></button>
            <div className="flex items-center space-x-3 mb-6">
              <div className={`p-2 rounded-[12px] ${cls.indigoIcon}`}><Pencil className="w-5 h-5" /></div>
              <h2 className={`text-xl font-semibold ${cls.textMain}`}>Edit Klik</h2>
            </div>
            <form onSubmit={executeEditClick} className="space-y-4 text-sm font-medium">
              <input type="date" value={editFormClick.dateFor} onChange={(e) => setEditFormClick({ ...editFormClick, dateFor: e.target.value })} className={`w-full p-3 ${cls.input}`} required />
              <input type="text" value={editFormClick.operator} onChange={(e) => setEditFormClick({ ...editFormClick, operator: e.target.value })} className={`w-full p-3 ${cls.input}`} required />
              <input type="number" value={editFormClick.totalClicks} onChange={(e) => setEditFormClick({ ...editFormClick, totalClicks: e.target.value })} className={`w-full p-3 ${cls.input}`} required />
              <button type="submit" className={`w-full mt-4 ${cls.indigoBg} text-white font-semibold py-3 rounded-full shadow-md`}>Simpan Perubahan</button>
            </form>
          </div>
        </div>
      )}

      {editPurchaseModal.isOpen && (
        <div className={`fixed inset-0 ${cls.modalBg} z-50 flex items-center justify-center p-4`}>
          <div className={`${cls.cardBg} w-full max-w-md p-6 relative`}>
            <button onClick={() => setEditPurchaseModal({ isOpen: false, item: null })} className={`absolute top-4 right-4 ${cls.textMuted} p-1 rounded-full hover:bg-black/5 dark:hover:bg-white/10`}><X className="w-5 h-5" /></button>
            <div className="flex items-center space-x-3 mb-6">
               <div className={`p-2 rounded-[12px] ${cls.amberIcon}`}><Pencil className="w-5 h-5" /></div>
               <h2 className={`text-xl font-semibold ${cls.textMain}`}>Edit Pembelian Part</h2>
            </div>
            <form onSubmit={executeEditPurchase} className="space-y-4 text-sm font-medium">
              <div><label className={`block text-xs mb-1.5 ml-1 ${cls.textSub}`}>Tgl Beli</label><input type="date" value={editPurchaseForm.tgl_pembelian} onChange={(e) => setEditPurchaseForm({ ...editPurchaseForm, tgl_pembelian: e.target.value })} className={`w-full p-3 ${cls.input}`} required /></div>
              <div>
                <label className={`block text-xs mb-1.5 ml-1 ${cls.textSub}`}>Nama Barang</label>
                <select value={editPurchaseForm.part_name} onChange={(e) => setEditPurchaseForm({ ...editPurchaseForm, part_name: e.target.value })} className={`w-full p-3 ${cls.input}`} required>
                  <option value="">Pilih Barang...</option>
                  {PREDEFINED_PARTS.map(part => <option key={part} value={part}>{part}</option>)}
                </select>
              </div>
              <div className="flex gap-3">
                <div className="flex-1"><label className={`block text-xs mb-1.5 ml-1 ${cls.textSub}`}>Qty</label><input type="number" value={editPurchaseForm.qty} onChange={(e) => setEditPurchaseForm({ ...editPurchaseForm, qty: e.target.value })} className={`w-full p-3 ${cls.input}`} required /></div>
                <div className="flex-1"><label className={`block text-xs mb-1.5 ml-1 ${cls.textSub}`}>Harga Satuan</label><input type="number" value={editPurchaseForm.harga_satuan} onChange={(e) => setEditPurchaseForm({ ...editPurchaseForm, harga_satuan: e.target.value })} className={`w-full p-3 ${cls.input}`} required /></div>
              </div>
              <div><label className={`block text-xs mb-1.5 ml-1 ${cls.textSub}`}>Supplier</label><input type="text" value={editPurchaseForm.supplier} onChange={(e) => setEditPurchaseForm({ ...editPurchaseForm, supplier: e.target.value })} className={`w-full p-3 ${cls.input}`} /></div>
              <button type="submit" className={`w-full mt-4 ${cls.amberBg} text-white font-semibold py-3 rounded-full shadow-md`}>Update Pembelian</button>
            </form>
          </div>
        </div>
      )}

      {editInventoryModal.isOpen && (
        <div className={`fixed inset-0 ${cls.modalBg} z-50 flex items-center justify-center p-4`}>
          <div className={`${cls.cardBg} w-full max-w-sm p-6 relative`}>
            <button onClick={() => setEditInventoryModal({ isOpen: false, item: null })} className={`absolute top-4 right-4 ${cls.textMuted} p-1 rounded-full hover:bg-black/5 dark:hover:bg-white/10`}><X className="w-5 h-5" /></button>
            <div className="flex items-center space-x-3 mb-6">
              <div className={`p-2 rounded-[12px] ${cls.emeraldIcon}`}><Package className="w-5 h-5" /></div>
              <h2 className={`text-xl font-semibold ${cls.textMain}`}>Edit Stok Gudang</h2>
            </div>
            <form onSubmit={executeEditInventory} className="space-y-4 text-sm font-medium">
              <div>
                <label className={`block text-xs mb-1.5 ml-1 ${cls.textSub}`}>Nama Barang</label>
                <select value={editInventoryForm.part_name} onChange={(e) => setEditInventoryForm({ ...editInventoryForm, part_name: e.target.value })} className={`w-full p-3 ${cls.input}`} required>
                  <option value="">Pilih Barang...</option>
                  {PREDEFINED_PARTS.map(part => <option key={part} value={part}>{part}</option>)}
                </select>
              </div>
              <div><label className={`block text-xs mb-1.5 ml-1 ${cls.textSub}`}>Sisa Stok Terkini</label><input type="number" value={editInventoryForm.stock} onChange={(e) => setEditInventoryForm({ ...editInventoryForm, stock: e.target.value })} className={`w-full p-3 ${cls.input}`} required /></div>
              <button type="submit" className={`w-full mt-4 ${cls.emeraldBg} text-white font-semibold py-3 rounded-full shadow-md`}>Simpan Stok</button>
            </form>
          </div>
        </div>
      )}

      {editErrorModal.isOpen && (
        <div className={`fixed inset-0 ${cls.modalBg} z-50 flex items-center justify-center p-4`}>
          <div className={`${cls.cardBg} w-full max-w-4xl p-8 relative overflow-y-auto max-h-[90vh]`}>
            <button onClick={() => setEditErrorModal({ isOpen: false, item: null })} className={`absolute top-6 right-6 ${cls.textMuted} p-1 rounded-full hover:bg-black/5 dark:hover:bg-white/10`}><X className="w-6 h-6" /></button>
            <div className={`flex items-center space-x-3 mb-8 pb-4 border-b ${cls.tableDiv}`}>
              <div className={`p-2 rounded-[14px] ${cls.roseIcon}`}><Pencil className="w-6 h-6" /></div>
              <h2 className={`text-2xl font-semibold tracking-tight ${cls.textMain}`}>Edit Log Error</h2>
            </div>
            <form onSubmit={executeEditError} className="grid grid-cols-1 md:grid-cols-3 gap-6 text-sm font-medium">
              <div className="space-y-4">
                <input type="date" value={editErrorForm.tgl} onChange={e => setEditErrorForm({ ...editErrorForm, tgl: e.target.value })} className={`w-full p-3 ${cls.input}`} required />
                <input type="text" value={editErrorForm.nomor_invoice} onChange={e => setEditErrorForm({ ...editErrorForm, nomor_invoice: e.target.value })} placeholder="Invoice" className={`w-full p-3 ${cls.input}`} />
                <input type="text" value={editErrorForm.nama_konsumen} onChange={e => setEditErrorForm({ ...editErrorForm, nama_konsumen: e.target.value })} placeholder="Konsumen" className={`w-full p-3 ${cls.input}`} required />
                <input type="text" value={editErrorForm.nama_produk} onChange={e => setEditErrorForm({ ...editErrorForm, nama_produk: e.target.value })} placeholder="Produk" className={`w-full p-3 ${cls.input}`} required />
              </div>
              <div className="space-y-4">
                <select value={editErrorForm.jenis_kesalahan} onChange={e => setEditErrorForm({ ...editErrorForm, jenis_kesalahan: e.target.value })} className={`w-full p-3 ${cls.input}`}><option value="Machine Error">Machine Error</option><option value="Human Error">Human Error</option><option value="Print Test">Print Test</option></select>
                <input type="number" value={editErrorForm.qty_kesalahan} onChange={e => setEditErrorForm({ ...editErrorForm, qty_kesalahan: e.target.value })} placeholder="Qty Rusak" className={`w-full p-3 ${cls.input}`} required />
                <input type="text" value={editErrorForm.pic} onChange={e => setEditErrorForm({ ...editErrorForm, pic: e.target.value })} placeholder="PIC" className={`w-full p-3 ${cls.input}`} required />
                <input type="number" value={editErrorForm.kerugian_bahan} onChange={e => setEditErrorForm({ ...editErrorForm, kerugian_bahan: e.target.value })} placeholder="Rugi Bahan (Rp)" className={`w-full p-3 ${cls.input}`} />
                <input type="number" value={editErrorForm.kerugian_jasa} onChange={e => setEditErrorForm({ ...editErrorForm, kerugian_jasa: e.target.value })} placeholder="Rugi Jasa (Rp)" className={`w-full p-3 ${cls.input}`} />
              </div>
              <div className="space-y-4 flex flex-col justify-between">
                <div className="space-y-4">
                  <textarea value={editErrorForm.deskripsi_kesalahan} onChange={e => setEditErrorForm({ ...editErrorForm, deskripsi_kesalahan: e.target.value })} className={`w-full p-3 ${cls.input}`} rows="2" placeholder="Deskripsi Masalah"></textarea>
                  <textarea value={editErrorForm.penyebab} onChange={e => setEditErrorForm({ ...editErrorForm, penyebab: e.target.value })} className={`w-full p-3 ${cls.input}`} rows="2" placeholder="Penyebab Kesalahan"></textarea>
                  <textarea value={editErrorForm.pencegahan_solusi} onChange={e => setEditErrorForm({ ...editErrorForm, pencegahan_solusi: e.target.value })} className={`w-full p-3 ${cls.input}`} rows="2" placeholder="Solusi & Pencegahan"></textarea>
                  <input type="text" value={editErrorForm.penyelesaian} onChange={e => setEditErrorForm({ ...editErrorForm, penyelesaian: e.target.value })} className={`w-full p-3 ${cls.input}`} placeholder="Penyelesaian Untuk Konsumen" />
                </div>
                <button type="submit" className={`w-full py-4 ${cls.roseBg} text-white font-semibold rounded-[16px] shadow-md mt-4`}>Simpan Perubahan</button>
              </div>
            </form>
          </div>
        </div>
      )}

      {errorDetailModal.isOpen && errorDetailModal.item && (
        <div className={`fixed inset-0 ${cls.modalBg} z-50 flex items-center justify-center p-4`}>
          <div className={`${cls.cardBg} w-full max-w-2xl p-0 relative overflow-hidden flex flex-col max-h-[90vh]`}>
            <div className={`p-6 flex items-center justify-between sticky top-0 z-10 border-b ${cls.cardHeader}`}>
              <div className="flex items-center space-x-4">
                <div className={`p-3 rounded-[16px] ${cls.roseIcon}`}><AlertTriangle className="w-7 h-7" /></div>
                <div>
                  <h2 className={`text-2xl font-semibold tracking-tight ${cls.textMain}`}>Detail Investigasi</h2>
                  <p className={`text-sm ${cls.textSub} mt-0.5`}>Dilaporkan: {formatDateToLocale(errorDetailModal.item.tgl)}</p>
                </div>
              </div>
              <button onClick={() => setErrorDetailModal({ isOpen: false, item: null })} className={`${cls.btnSec} p-2`}><X className="w-5 h-5" /></button>
            </div>

            <div className={`p-6 overflow-y-auto text-sm bg-transparent`}>
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className="space-y-6">
                  <div>
                    <h3 className={`text-xs font-bold uppercase tracking-wider ${cls.textMuted} mb-3 ml-1`}>Informasi Pesanan</h3>
                    <div className={`${cls.cardHeader} p-4 rounded-[20px] space-y-3`}>
                      <div className="flex justify-between"><span className={cls.textSub}>Invoice</span><span className={`font-mono font-semibold ${cls.indigoText}`}>{errorDetailModal.item.nomor_invoice || '-'}</span></div>
                      <div className="flex justify-between"><span className={cls.textSub}>Konsumen</span><span className={`font-semibold ${cls.textMain}`}>{errorDetailModal.item.nama_konsumen}</span></div>
                      <div className="flex justify-between"><span className={cls.textSub}>Produk</span><span className={`font-semibold ${cls.textMain}`}>{errorDetailModal.item.nama_produk}</span></div>
                      <div className="flex justify-between pt-2 border-t border-black/5 dark:border-white/5"><span className={cls.textSub}>PIC</span><span className={`font-semibold ${cls.textMain}`}>{errorDetailModal.item.pic}</span></div>
                    </div>
                  </div>

                  <div>
                     <h3 className={`text-xs font-bold uppercase tracking-wider ${cls.roseText} mb-3 ml-1 opacity-80`}>Dampak Finansial</h3>
                     <div className={`${cls.roseIcon} p-4 rounded-[20px]`}>
                        {(() => {
                          const base = Number(errorDetailModal.item.kerugian_bahan) + Number(errorDetailModal.item.kerugian_jasa);
                          const total = Number(errorDetailModal.item.jumlah_kerugian);
                          const mult = (base > 0 && total > base) ? Math.round(total / base) : 1;
                          return (
                            <>
                              <div className="flex justify-between items-center mb-1">
                                <span className={`text-sm font-medium ${cls.roseText}`}>Total Kerugian</span>
                                <span className={`font-mono font-bold text-xl ${cls.roseText}`}>Rp {total.toLocaleString('id-ID')}</span>
                              </div>
                              {mult > 1 && <div className={`text-xs font-semibold ${cls.roseText} mt-2 pt-2 border-t border-rose-500/20`}>Termasuk Penalty {mult}x Lipat!</div>}
                            </>
                          );
                        })()}
                     </div>
                  </div>
                </div>

                <div className="space-y-6">
                  <div>
                    <h3 className={`text-xs font-bold uppercase tracking-wider ${cls.textMuted} mb-3 ml-1`}>Analisis & Penyelesaian</h3>
                    <div className={`${cls.cardHeader} p-5 rounded-[20px] space-y-4`}>
                      <div className="flex gap-3">
                        <div className={`flex-1 ${cls.amberIcon} p-3 rounded-[14px]`}><p className="text-[10px] uppercase font-bold mb-1 opacity-70">Kategori</p><p className="font-semibold text-xs">{errorDetailModal.item.kategori_kesalahan}</p></div>
                        <div className={`flex-1 ${cls.indigoIcon} p-3 rounded-[14px]`}><p className="text-[10px] uppercase font-bold mb-1 opacity-70">Jenis Error</p><p className="font-semibold text-xs">{errorDetailModal.item.jenis_kesalahan}</p></div>
                      </div>
                      <div>
                        <p className={`text-xs ${cls.textSub} mb-1 ml-1`}>Deskripsi Kesalahan</p>
                        <div className={`bg-black/5 dark:bg-white/5 p-3 rounded-[14px] ${cls.textMain} text-xs leading-relaxed font-semibold`}>{errorDetailModal.item.deskripsi_kesalahan || <i>Belum ada deskripsi kesalahan.</i>}</div>
                      </div>
                      <div>
                        <p className={`text-xs ${cls.textSub} mb-1 ml-1`}>Penyebab</p>
                        <div className={`bg-black/5 dark:bg-white/5 p-3 rounded-[14px] ${cls.textMain} text-xs leading-relaxed font-semibold`}>{errorDetailModal.item.penyebab || <i>Belum ada analisis penyebab kesalahan.</i>}</div>
                      </div>
                      <div>
                        <p className={`text-xs ${cls.textSub} mb-1 ml-1`}>Solusi & Pencegahan</p>
                        <div className={`${cls.emeraldIcon} p-3 rounded-[14px] text-xs font-semibold`}>{errorDetailModal.item.pencegahan_solusi || <i>Belum ada solusi & pencegahan.</i>}</div>
                      </div>
                      <div className={`pt-3 flex justify-between items-center border-t border-black/5 dark:border-white/5`}>
                        <div><p className={`text-xs ${cls.textSub}`}>Penyelesaian Konsumen</p><p className={`font-semibold ${errorDetailModal.item.penyelesaian?.includes('PUNISHMENT') ? cls.roseText : cls.textMain}`}>{errorDetailModal.item.penyelesaian || '-'}</p></div>
                        <div className="text-right"><p className={`text-xs ${cls.textSub}`}>Qty Rusak</p><p className={`font-bold ${cls.roseText} text-xl`}>{errorDetailModal.item.qty_kesalahan} <span className="text-xs font-medium">pcs</span></p></div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
            
            <div className={`p-4 border-t ${cls.cardHeader} text-center`}>
              <button onClick={() => setErrorDetailModal({ isOpen: false, item: null })} className={`px-8 py-2.5 font-semibold text-[15px] rounded-full transition-colors ${cls.btnSec}`}>Tutup</button>
            </div>
          </div>
        </div>
      )}

      {showLoginModal && (
        <div className={`fixed inset-0 ${cls.modalBg} z-50 flex items-center justify-center p-4`}>
          <div className={`${cls.cardBg} w-full max-w-sm p-8 relative`}>
            <button onClick={() => setShowLoginModal(false)} className={`absolute top-4 right-4 ${cls.textMuted} p-1 rounded-full hover:bg-black/5 dark:hover:bg-white/10`}><X className="w-5 h-5" /></button>
            <div className="flex flex-col items-center text-center mb-6">
              <div className={`p-4 rounded-full ${cls.indigoIcon} mb-4`}><Lock className="w-8 h-8" /></div>
              <h2 className={`text-2xl font-semibold tracking-tight ${cls.textMain}`}>Otorisasi</h2>
              <p className={`text-sm ${cls.textSub} mt-1`}>Masukkan kata sandi admin</p>
            </div>
            <form onSubmit={handleAdminLogin} className="space-y-5">
              <input type="password" value={adminPwdInput} onChange={(e) => setAdminPwdInput(e.target.value)} autoFocus className={`w-full p-3.5 text-center tracking-[0.3em] font-mono text-lg ${cls.input}`} placeholder="•••••" />
              <button type="submit" className={`w-full ${cls.indigoBg} text-white font-semibold py-3.5 rounded-full shadow-lg`}>Buka Kunci</button>
            </form>
          </div>
        </div>
      )}

      {toast && (
        <div className={`fixed top-8 right-8 z-50 flex items-center px-5 py-4 rounded-[18px] shadow-[0_8px_30px_rgb(0,0,0,0.12)] text-white backdrop-blur-xl transition-all ${toast.type === 'error' ? 'bg-[#FF3B30]/90' : 'bg-[#32D74B]/90'}`}>
          {toast.type === 'error' ? <AlertCircle className="w-5 h-5 mr-3" /> : <CheckCircle className="w-5 h-5 mr-3" />} 
          <span className="font-semibold text-[15px] tracking-tight">{toast.message}</span>
        </div>
      )}

      {/* --- HEADER UTAMA (APPLE LARGE TITLE STYLE) --- */}
      <div className={`mb-8 flex flex-col md:flex-row justify-between items-start md:items-end gap-4`}>
        <div className="flex items-center space-x-4">
          <div className={`w-14 h-14 rounded-[16px] flex items-center justify-center shadow-sm ${cls.indigoBg}`}>
            <Printer className="w-7 h-7 text-white" />
          </div>
          <div>
            <h1 className={`text-3xl md:text-4xl font-bold tracking-tight ${cls.textMain}`}>Konica Tracker</h1>
            <p className={`text-sm font-medium mt-1 ${cls.textSub}`}>Sistem Manajemen Mesin & Log</p>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-3">
          <div className={`flex items-center space-x-3 py-1.5 px-1.5 pr-5 rounded-full border shadow-sm ${cls.cardBg}`}>
            <div className={`p-2 rounded-full ${cls.indigoBg} text-white`}><Activity className="w-4 h-4" /></div>
            <div>
              <p className={`text-[10px] font-bold uppercase tracking-wider ${cls.textMuted}`}>Total Lifetime</p>
              <h3 className={`text-sm font-bold font-mono leading-none ${cls.textMain}`}>{currentTotalClicks.toLocaleString()}</h3>
            </div>
          </div>

          <button onClick={toggleTheme} className={`p-3 rounded-full shadow-sm ${cls.cardBg}`} title="Ganti Tema">
            {isDarkMode ? <Sun className={`w-5 h-5 ${cls.amberText}`} /> : <Moon className={`w-5 h-5 ${cls.indigoText}`} />}
          </button>

          {isAdmin ? (
            <button onClick={() => setIsAdmin(false)} className={`py-2.5 px-5 rounded-full font-semibold text-[15px] shadow-sm border border-[#FF3B30]/20 ${cls.roseIcon}`}>
              <Unlock className="w-4 h-4 mr-2 inline" /> Admin Mode
            </button>
          ) : (
            <button onClick={() => setShowLoginModal(true)} className={`py-2.5 px-5 rounded-full font-semibold text-[15px] shadow-sm ${cls.cardBg}`}>
              <Lock className="w-4 h-4 mr-2 inline" /> Login
            </button>
          )}
        </div>
      </div>

      <div className="grid grid-cols-1 lg:grid-cols-4 gap-6 md:gap-8">
        {/* KOLOM KIRI (INPUT) */}
        <div className="space-y-6">
          <div className={`p-6 ${cls.cardBg}`}>
            <h2 className={`font-semibold text-xl mb-5 flex items-center tracking-tight ${cls.textMain}`}><PlusCircle className={`w-5 h-5 mr-2 ${cls.indigoText}`} /> Input Shift</h2>
            <form onSubmit={handleSaveData} className="space-y-4 font-medium">
              <select value={dateType} onChange={(e) => setDateType(e.target.value)} className={`w-full p-3.5 ${cls.input} bg-[#0A84FF]`}><option value="Hari Ini">Data Hari Ini</option><option value="Kemarin">Data Kemarin</option></select>
              <input type="text" value={operator} onChange={(e) => setOperator(e.target.value)} placeholder="Nama Operator" className={`w-full p-3.5 ${cls.input}`} />
              <input type="number" value={newClicks} onChange={(e) => setNewClicks(e.target.value)} placeholder="Total Klik Mesin" className={`w-full p-3.5 font-bold ${cls.input}`} />
              <button type="submit" className={`w-full ${cls.indigoBg} text-white py-3.5 rounded-[14px] font-semibold mt-2 shadow-sm hover:opacity-90 transition-opacity`}>Simpan Pemakaian</button>
            </form>
          </div>

          <div className={`p-6 relative overflow-hidden ${cls.cardBg}`}>
            {/* Hanya Mengaktifkan Reset jika pengguna masuk sebagai Admin */}
            {!isAdmin && <div className={`absolute inset-0 z-10 flex flex-col items-center justify-center ${cls.lockOverlay}`}><Lock className="w-8 h-8 mb-2 opacity-80" /><span className="font-semibold tracking-tight">Perlu Akses Admin</span></div>}
            <h2 className={`font-semibold text-xl mb-5 flex items-center tracking-tight ${cls.textMain}`}><RotateCcw className={`w-5 h-5 mr-2 ${cls.amberText}`} /> Reset Part</h2>
            <form onSubmit={handleReplacePart} className="space-y-4 font-medium">
              {/* DROPDOWN DINAMIS: HANYA MENAMPILKAN BARANG YANG ADA DI GUDANG DENGAN STOK > 0 */}
              <div>
                <label className={`block text-xs mb-1.5 ml-1 ${cls.textSub}`}>Suku Cadang Tersedia</label>
                <select 
                  disabled={!isAdmin} 
                  value={replacePart} 
                  onChange={(e) => setReplacePart(e.target.value)} 
                  className={`w-full p-3.5 ${isAdmin ? cls.input : cls.inputDisabled}`}
                  required
                >
                  <option value="">Pilih Suku Cadang...</option>
                  {availablePartsInWarehouse.map(p => (
                    <option key={p} value={p}>{p}</option>
                  ))}
                </select>
                {isAdmin && availablePartsInWarehouse.length === 0 && (
                  <p className="text-[11px] text-[#FF453A] mt-1.5 ml-1">Semua stok di gudang habis! Lakukan pembelian terlebih dahulu.</p>
                )}
              </div>
              <input disabled={!isAdmin} type="text" value={replaceOperator} onChange={(e) => setReplaceOperator(e.target.value)} placeholder="Nama Teknisi" className={`w-full p-3.5 ${isAdmin ? cls.input : cls.inputDisabled}`} required />
              <input disabled={!isAdmin} type="number" value={replaceClick} onChange={(e) => setReplaceClick(e.target.value)} placeholder={`Klik Terakhir (${currentTotalClicks})`} className={`w-full p-3.5 ${isAdmin ? cls.input : cls.inputDisabled}`} />
              <button type="submit" disabled={!isAdmin || availablePartsInWarehouse.length === 0} className={`w-full py-3.5 rounded-[14px] font-semibold mt-2 ${isAdmin && availablePartsInWarehouse.length > 0 ? `${cls.amberBg} text-white shadow-sm` : cls.inputDisabled}`}>Lakukan Reset</button>
            </form>
          </div>
        </div>

        {/* KOLOM KANAN (TABS) */}
        <div className="lg:col-span-3 space-y-6">
          
          {/* Segmented Control Mac Style */}
          <div className="flex justify-center w-full">
            <div className={`flex w-full md:w-auto overflow-x-auto hide-scrollbar ${cls.tabContainer}`}>
              <button onClick={() => setActiveTab('dashboard')} className={`flex-1 min-w-[120px] px-5 py-2 text-[15px] flex justify-center items-center whitespace-nowrap transition-all ${activeTab === 'dashboard' ? cls.tabAct : cls.tabInact}`}>Dashboard</button>
              <button onClick={() => setActiveTab('inventory')} className={`flex-1 min-w-[130px] px-5 py-2 text-[15px] flex justify-center items-center whitespace-nowrap transition-all ${activeTab === 'inventory' ? cls.tabAct : cls.tabInact}`}>Stok & Beli</button>
              <button onClick={() => setActiveTab('monitoring')} className={`flex-1 min-w-[120px] px-5 py-2 text-[15px] flex justify-center items-center whitespace-nowrap transition-all ${activeTab === 'monitoring' ? cls.tabAct : cls.tabInact}`}>Umur Part</button>
              <button onClick={() => setActiveTab('error_log')} className={`flex-1 min-w-[130px] px-5 py-2 text-[15px] flex justify-center items-center whitespace-nowrap transition-all ${activeTab === 'error_log' ? cls.tabAct : cls.tabInact}`}>Log Error</button>
              <button onClick={() => setActiveTab('report')} className={`flex-1 min-w-[120px] px-5 py-2 text-[15px] flex justify-center items-center whitespace-nowrap transition-all ${activeTab === 'report' ? cls.tabAct : cls.tabInact}`}>Laporan</button>
            </div>
          </div>

          {/* PERIODE KALKULASI DISETTING AGAR MUNCUL JUGA DI TAB MONITORING (UMUR PART) */}
          {(activeTab === 'dashboard' || activeTab === 'report' || activeTab === 'inventory' || activeTab === 'error_log' || activeTab === 'monitoring') && (
            <div className={`px-6 py-4 flex flex-col sm:flex-row justify-between sm:items-center gap-4 ${cls.cardBg}`}>
              <div className="flex items-center space-x-2"><Filter className={`w-5 h-5 ${cls.textMuted}`} /><h2 className={`font-semibold text-lg tracking-tight ${cls.textMain}`}>Periode Kalkulasi</h2></div>
              <div className="flex flex-wrap gap-2 text-sm font-medium">
                <select value={reportPeriod} onChange={(e) => setReportPeriod(e.target.value)} className={`py-2 px-4 ${cls.input} border-none shadow-none bg-black/5 dark:bg-white/10`}><option value="today">Hari Ini</option><option value="week">7 Hari Terakhir</option><option value="month">Bulan Ini</option><option value="custom">Tentukan Tanggal...</option></select>
                {reportPeriod === 'custom' && (
                  <div className={`flex items-center space-x-2 border rounded-lg px-2 py-1 ${cls.cardBg}`}>
                    <input type="date" value={customStart} onChange={e => setCustomStart(e.target.value)} onClick={(e) => { try { e.target.showPicker(); } catch(err){} }} className={`border-none bg-transparent text-sm focus:ring-0 p-1 ${cls.textMain} [color-scheme:${isDarkMode ? 'dark' : 'light'}]`} />
                    <span className={cls.textMuted}>-</span>
                    <input type="date" value={customEnd} onChange={e => setCustomEnd(e.target.value)} onClick={(e) => { try { e.target.showPicker(); } catch(err){} }} className={`border-none bg-transparent text-sm focus:ring-0 p-1 ${cls.textMain} [color-scheme:${isDarkMode ? 'dark' : 'light'}]`} />
                  </div>
                )}
              </div>
            </div>
          )}

          {/* TAB 0: DASHBOARD */}
          {activeTab === 'dashboard' && (
            <div className="space-y-6 animate-in fade-in duration-500">
              {/* Top Cards Apple Health Style */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                
                {/* OUTPUT TERCETAK DENGAN ADVANCED METRIC (CLICK PER PCS) */}
                <div className={`p-6 flex flex-col justify-between h-40 ${cls.cardBg}`}>
                   <div className="flex justify-between items-start">
                     <div className={`w-10 h-10 rounded-full flex items-center justify-center ${cls.indigoIcon}`}><Printer className="w-5 h-5" /></div>
                     <span className={`text-xs font-bold uppercase tracking-wider ${cls.textMuted}`}>Output Tercetak &amp; Efisiensi</span>
                   </div>
                   <div className="flex items-end justify-between">
                     <div>
                       <p className={`text-[10px] font-bold uppercase tracking-wider ${cls.textMuted} mb-1`}>Total Output</p>
                       <h3 className={`text-xl md:text-2xl font-bold font-mono ${cls.textMain}`}>{totalReportUsage.toLocaleString()} <span className={`text-xs font-medium ${cls.textMuted}`}>klik</span></h3>
                     </div>
                     {/* Pembeda untuk Harga Click per Pcs - Diubah menjadi berwarna hijau dan size lebih besar */}
                     <div className="text-right border-l border-[#38383A]/20 dark:border-[#38383A] pl-5">
                       <p className={`text-[10px] font-bold uppercase tracking-wider ${cls.textMuted} mb-1`}>Biaya Part / Klik</p>
                       <h4 className={`text-3xl md:text-4xl font-extrabold font-mono ${cls.emeraldText}`}>
                         Rp {clickPerPcsValue.toLocaleString('id-ID', { maximumFractionDigits: 1 })}
                       </h4>
                     </div>
                   </div>
                </div>

                <div className={`p-6 flex flex-col justify-between h-40 ${cls.cardBg}`}>
                   <div className="flex justify-between items-start">
                     <div className={`w-10 h-10 rounded-full flex items-center justify-center ${cls.emeraldIcon}`}><DollarSign className="w-5 h-5" /></div>
                     <span className={`text-xs font-bold uppercase tracking-wider ${cls.textMuted}`}>Est. Pendapatan Kotor</span>
                   </div>
                   <div>
                     <h3 className={`text-4xl font-bold tracking-tight font-mono ${cls.textMain}`}>Rp {totalGrossRevenue.toLocaleString('id-ID')}</h3>
                   </div>
                </div>
              </div>

              {/* Expense & Net Margin */}
              <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div className={`p-6 flex flex-col justify-center space-y-5 ${cls.cardBg}`}>
                  <div className="flex items-center justify-between">
                    <div className="flex items-center space-x-3"><div className={`p-2.5 rounded-[12px] ${cls.amberIcon}`}><ShoppingCart className="w-5 h-5" /></div><p className={`font-semibold ${cls.textMain}`}>Pengeluaran Part</p></div>
                    <h3 className={`text-lg font-bold font-mono ${cls.textMain}`}>- Rp {totalExpensePart.toLocaleString('id-ID')}</h3>
                  </div>
                  <div className="h-px w-full bg-black/5 dark:bg-white/10"></div>
                  <div className="flex items-center justify-between">
                    <div className="flex items-center space-x-3"><div className={`p-2.5 rounded-[12px] ${cls.roseIcon}`}><AlertTriangle className="w-5 h-5" /></div><p className={`font-semibold ${cls.textMain}`}>Kerugian Error</p></div>
                    <h3 className={`text-lg font-bold font-mono ${cls.textMain}`}>- Rp {totalRupiahKerugian.toLocaleString('id-ID')}</h3>
                  </div>
                </div>
                <div className={`p-6 flex flex-col justify-center items-center text-center ${cls.cardBg} ${netMargin >= 0 ? 'border-[2px] border-[#32D74B]/20' : 'border-[2px] border-[#FF453A]/20'}`}>
                  <p className={`text-sm font-bold uppercase tracking-wider ${netMargin >= 0 ? cls.emeraldText : cls.roseText} mb-2`}>Total Margin Bersih</p>
                  <h3 className={`text-4xl md:text-5xl font-bold tracking-tight font-mono ${cls.textMain}`}>Rp {netMargin.toLocaleString('id-ID')}</h3>
                </div>
              </div>

              {/* COMPACT CHARTS (50/50 SPLIT) */}
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-4 md:gap-6">
                {/* Chart Tren */}
                <div className={`p-4 md:p-5 ${cls.cardBg} flex flex-col`}>
                  <h3 className={`font-semibold text-[15px] tracking-tight mb-4 flex items-center ${cls.textMain}`}>
                    <TrendingUp className={`w-4 h-4 mr-2 ${cls.indigoText}`} /> Tren Harian
                  </h3>
                  <div className={`h-32 flex items-end gap-1.5 mt-auto relative border-b pb-1 ${cls.chartAxisLine}`}>
                    {clickChartData.map((d, i) => (
                      <div key={i} className="flex-1 flex flex-col items-center group relative h-full justify-end">
                        <div className={`absolute -top-10 opacity-0 group-hover:opacity-100 text-[11px] py-1 px-2 whitespace-nowrap ${cls.chartTooltip}`}>
                          {d.fullDate}: <span className="font-bold">{d.total}</span> klik
                        </div>
                        <div className={`w-full max-w-[28px] rounded-t-[6px] transition-all duration-500 ease-out ${cls.indigoBg} hover:opacity-80`} style={{ height: maxClickChart > 0 ? `${(d.total / maxClickChart) * 100}%` : '0%' }}></div>
                        <div className={`text-[9px] mt-1.5 font-medium ${cls.textMuted} hidden sm:block`}>{d.shortDate}</div>
                      </div>
                    ))}
                    {clickChartData.length === 0 && <div className={`w-full text-center self-center text-xs ${cls.textMuted}`}>Tidak ada data tren di periode ini.</div>}
                  </div>
                </div>

                {/* Chart Pie Error */}
                <div className={`p-4 md:p-5 ${cls.cardBg} flex flex-col`}>
                  <h3 className={`font-semibold text-[15px] tracking-tight mb-4 flex items-center ${cls.textMain}`}>
                    <PieChart className={`w-4 h-4 mr-2 ${cls.roseText}`} /> Rasio Error
                  </h3>
                  {errorChartData.length > 0 ? (
                    <div className="flex-grow flex flex-col justify-center space-y-4">
                      <div className={`h-4 flex rounded-full overflow-hidden w-full bg-black/5 dark:bg-white/10`}>
                        {errorChartData.map((d, i) => (
                          <div key={i} className={`h-full ${d.color}`} style={{ width: totalRupiahKerugian > 0 ? `${(d.value / totalRupiahKerugian) * 100}%` : '0%' }}></div>
                        ))}
                      </div>
                      <div className="grid grid-cols-2 gap-x-4 gap-y-2">
                        {errorChartData.map((d, i) => (
                          <div key={i} className="flex justify-between items-center">
                            <div className="flex items-center">
                              <div className={`w-2.5 h-2.5 rounded-full mr-2 ${d.color}`}></div>
                              <span className={`text-[11px] font-medium ${cls.textMain} truncate max-w-[70px]`}>{d.name}</span>
                            </div>
                            <span className={`text-[11px] font-bold font-mono ${cls.textSub}`}>{(d.value/1000).toFixed(0)}k</span>
                          </div>
                        ))}
                      </div>
                    </div>
                  ) : (
                    <div className={`flex-grow flex items-center justify-center text-xs font-medium ${cls.textMuted}`}>
                      Sistem berjalan sempurna.
                    </div>
                  )}
                </div>
              </div>

            </div>
          )}

          {/* TAB: INVENTORY */}
          {activeTab === 'inventory' && (
            <div className="space-y-6 animate-in fade-in duration-500">
              <div className="grid grid-cols-1 lg:grid-cols-3 gap-6">
                
                <div className={`col-span-1 p-6 ${cls.cardBg}`}>
                  <h3 className={`font-semibold text-xl mb-6 flex items-center tracking-tight ${cls.textMain}`}><ShoppingCart className={`w-5 h-5 mr-2 ${cls.amberText}`} /> Catat Pembelian</h3>
                  <form onSubmit={handleSavePurchase} className="space-y-4 font-medium">
                    <div><label className={`block text-xs mb-1.5 ml-1 ${cls.textSub}`}>Tgl Beli</label><input type="date" value={purchaseForm.tgl_pembelian} onChange={e => setPurchaseForm({...purchaseForm, tgl_pembelian: e.target.value})} className={`w-full p-3.5 ${cls.input}`} required /></div>
                    <div>
                      <label className={`block text-xs mb-1.5 ml-1 ${cls.textSub}`}>Nama Barang</label>
                      <select value={purchaseForm.part_name} onChange={e => setPurchaseForm({...purchaseForm, part_name: e.target.value})} className={`w-full p-3.5 ${cls.input}`} required>
                        <option value="">Pilih Barang...</option>
                        {PREDEFINED_PARTS.map(part => <option key={part} value={part}>{part}</option>)}
                      </select>
                    </div>
                    <div className="flex gap-3">
                      <div className="flex-1"><label className={`block text-xs mb-1.5 ml-1 ${cls.textSub}`}>Qty</label><input type="number" value={purchaseForm.qty} onChange={e => setPurchaseForm({...purchaseForm, qty: e.target.value})} className={`w-full p-3.5 ${cls.input}`} required /></div>
                      <div className="flex-1"><label className={`block text-xs mb-1.5 ml-1 ${cls.textSub}`}>Harga/Pcs</label><input type="number" value={purchaseForm.harga_satuan} onChange={e => setPurchaseForm({...purchaseForm, harga_satuan: e.target.value})} className={`w-full p-3.5 ${cls.input}`} required /></div>
                    </div>
                    <div><label className={`block text-xs mb-1.5 ml-1 ${cls.textSub}`}>Supplier</label><input type="text" value={purchaseForm.supplier} onChange={e => setPurchaseForm({...purchaseForm, supplier: e.target.value})} className={`w-full p-3.5 ${cls.input}`} /></div>
                    <button type="submit" className={`w-full mt-2 ${cls.amberBg} text-white font-semibold py-3.5 rounded-[14px] shadow-sm hover:opacity-90`}>Tambah ke Gudang</button>
                  </form>
                </div>

                <div className="col-span-1 lg:col-span-2 space-y-6">
                  <div className={`overflow-hidden ${cls.cardBg}`}>
                    <div className={`p-5 border-b flex justify-between items-center ${cls.tableDiv}`}>
                      <h3 className={`font-semibold text-lg flex items-center tracking-tight ${cls.textMain}`}><Package className={`w-5 h-5 mr-2 ${cls.emeraldText}`} /> Stok Gudang (Akumulasi)</h3>
                    </div>
                    <div className="overflow-y-auto max-h-[300px]">
                      <table className="w-full text-left text-[15px] whitespace-nowrap">
                        <thead className={`sticky top-0 z-10 border-b ${cls.tableHead}`}><tr><th className="px-5 py-3 font-semibold">Nama Barang</th><th className="px-5 py-3 font-semibold text-right">Stok Tersedia</th></tr></thead>
                        <tbody className={`divide-y ${cls.tableDiv}`}>
                          {inventory.map((item, idx) => (
                            <tr key={idx} className={`${cls.tableRow}`}>
                              <td className={`px-5 py-4 font-semibold ${cls.textMain}`}>{item.part_name}</td>
                              <td className={`px-5 py-4 text-right font-bold ${item.stock > 0 ? cls.indigoText : cls.roseText}`}>
                                <div className="flex items-center justify-end space-x-4">
                                  <span className="text-[16px]">{item.stock}</span>
                                  <button onClick={() => handleDecreaseStock(item)} className={`p-1.5 rounded-full ${cls.roseIcon} hover:bg-[#FF3B30] hover:text-white transition-all`} title="Pakai 1 Part">
                                    <Minus className="w-4 h-4" />
                                  </button>
                                </div>
                              </td>
                            </tr>
                          ))}
                          {inventory.length === 0 && <tr><td colSpan="2" className={`p-8 text-center font-medium ${cls.textMuted}`}>Gudang kosong.</td></tr>}
                        </tbody>
                      </table>
                    </div>
                  </div>
                  
                  <div className={`overflow-hidden ${cls.cardBg}`}>
                    <div className={`p-5 border-b flex justify-between items-center ${cls.tableDiv}`}>
                      <h3 className={`font-semibold text-lg tracking-tight flex items-center ${cls.textMain}`}>Histori Pembelian (Log Harian)</h3>
                      <div className={`px-4 py-1.5 rounded-full text-xs font-bold ${cls.amberIcon}`}>Rp {totalExpensePart.toLocaleString('id-ID')}</div>
                    </div>
                    <div className="overflow-x-auto max-h-[250px]">
                      <table className="w-full text-left text-[15px] whitespace-nowrap">
                        <thead className={`sticky top-0 z-10 border-b ${cls.tableHead}`}><tr><th className="px-5 py-3 font-semibold">Tgl Beli</th><th className="px-5 py-3 font-semibold">Nama Part / Supplier</th><th className="px-5 py-3 font-semibold text-center">Qty</th><th className="px-5 py-3 font-semibold text-right">Total (Rp)</th></tr></thead>
                        <tbody className={`divide-y ${cls.tableDiv}`}>
                          {reportPurchases.map((item, idx) => (
                            <tr key={idx} className={cls.tableRow}>
                              <td className={`px-5 py-3.5 font-medium ${cls.textSub}`}>{formatDateToLocale(item.tgl_pembelian)}</td>
                              <td className={`px-5 py-3.5`}>
                                <div className={`font-semibold ${cls.textMain}`}>{item.part_name}</div>
                                {item.supplier && <div className={`text-[11px] font-medium ${cls.textSub} mt-0.5`}>{item.supplier}</div>}
                              </td>
                              <td className={`px-5 py-3.5 text-center font-semibold ${cls.emeraldText}`}>+{item.qty}</td>
                              <td className={`px-5 py-3.5 text-right font-mono font-bold ${cls.amberText}`}>{Number(item.total_harga).toLocaleString('id-ID')}</td>
                            </tr>
                          ))}
                          {reportPurchases.length === 0 && <tr><td colSpan="4" className={`p-8 text-center font-medium ${cls.textMuted}`}>Tidak ada histori pembelian.</td></tr>}
                        </tbody>
                      </table>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          )}

          {/* --- TAB MONITORING REWORK: MENGGABUNGKAN STATUS UMUR DAN RIWAYAT RESET SUKU CADANG (BERELASI DENGAN PERIODE KALKULASI) --- */}
          {activeTab === 'monitoring' && (
             <div className="space-y-6 animate-in fade-in duration-500">
               {/* 1. Status Suku Cadang (Umur Part) */}
               <div className={`overflow-hidden ${cls.cardBg}`}>
                 <div className={`p-6 border-b flex items-center ${cls.tableDiv}`}>
                   <Settings className={`w-6 h-6 mr-3 ${cls.textMuted}`} />
                   <h2 className={`text-xl font-semibold tracking-tight ${cls.textMain}`}>Status Suku Cadang</h2>
                 </div>
                 <div className="overflow-x-auto max-h-[400px]">
                   <table className="w-full text-left text-[15px] whitespace-nowrap">
                     <thead className={`sticky top-0 z-10 border-b ${cls.tableHead}`}>
                       <tr>
                         <th className="px-6 py-4 font-semibold">Nama Suku Cadang</th>
                         <th className="px-6 py-4 font-semibold text-right">Dipakai</th>
                         <th className="px-6 py-4 font-semibold">Indikator Umur</th>
                         <th className="px-6 py-4 font-semibold text-right">Est. Ganti</th>
                       </tr>
                     </thead>
                     <tbody className={`divide-y ${cls.tableDiv}`}>
                       {partData.map((part, idx) => (
                         <tr key={idx} className={cls.tableRow}>
                           <td className={`px-6 py-4 font-semibold ${cls.textMain}`}>{part.name}</td>
                           <td className={`px-6 py-4 text-right font-mono font-medium ${cls.textSub}`}>{part.usage.toLocaleString()}</td>
                           <td className="px-6 py-4">
                             <div className="flex items-center">
                               <div className={`w-full rounded-full h-2 mr-4 bg-black/5 dark:bg-white/10 overflow-hidden`}>
                                 <div className={`h-2 rounded-full ${part.color}`} style={{ width: `${part.remainingPercent}%` }}></div>
                               </div>
                               <span className={`text-sm font-bold w-12 text-right ${part.remainingPercent <= 10 ? cls.roseText : (part.remainingPercent <= 25 ? cls.amberText : cls.emeraldText)}`}>{part.remainingPercent}%</span>
                             </div>
                           </td>
                           <td className={`px-6 py-4 text-right font-mono font-medium ${cls.textSub}`}>{part.estimatedReplace.toLocaleString()}</td>
                         </tr>
                       ))}
                     </tbody>
                   </table>
                 </div>
               </div>

               {/* 2. Riwayat Pergantian Suku Cadang (Menyerap Filter Periode Kalkulasi secara dinamis) */}
               <div className={`overflow-hidden ${cls.cardBg}`}>
                 <div className={`p-6 border-b flex items-center ${cls.tableDiv}`}>
                   <RotateCcw className={`w-6 h-6 mr-3 ${cls.amberText}`} />
                   <h2 className={`text-xl font-semibold tracking-tight ${cls.textMain}`}>Riwayat Pergantian Suku Cadang (Periode Terpilih)</h2>
                 </div>
                 <div className="overflow-x-auto max-h-[300px] overflow-y-auto">
                   <table className="w-full text-left text-[15px] whitespace-nowrap">
                     <thead className={`sticky top-0 z-10 border-b ${cls.tableHead}`}>
                       <tr>
                         <th className="px-5 py-3 font-semibold">Tanggal Ganti</th>
                         <th className="px-5 py-3 font-semibold">Suku Cadang</th>
                         <th className="px-5 py-3 font-semibold">Teknisi / PIC</th>
                         <th className="px-5 py-3 text-right font-semibold">Di Klik Ke-</th>
                       </tr>
                     </thead>
                     <tbody className={`divide-y ${cls.tableDiv}`}>
                       {reportReplacements.map(item => (
                         <tr key={item.id} className={cls.tableRow}>
                           <td className={`px-5 py-3.5 ${cls.textSub}`}>{formatDateToLocale(item.createdAt)}</td>
                           <td className={`px-5 py-3.5 font-semibold ${cls.amberText}`}>{item.partName}</td>
                           <td className={`px-5 py-3.5 font-medium ${cls.textSub}`}>{item.operator}</td>
                           <td className={`px-5 py-3.5 text-right font-mono font-bold ${cls.textMain}`}>{item.replacedAtClick.toLocaleString()}</td>
                         </tr>
                       ))}
                       {reportReplacements.length === 0 && (
                         <tr>
                           <td colSpan="4" className={`text-center py-8 ${cls.textMuted}`}>Tidak ada pergantian part di periode kalkulasi ini.</td>
                         </tr>
                       )}
                     </tbody>
                   </table>
                 </div>
               </div>
             </div>
          )}

          {/* TAB: ERROR LOG */}
          {activeTab === 'error_log' && (
             <div className="grid grid-cols-1 xl:grid-cols-5 gap-6 md:gap-8 animate-in fade-in duration-500">
               
               {/* SISI KIRI (2/5): Form Pencatatan Log Error */}
               <div className={`xl:col-span-2 p-6 h-fit ${cls.cardBg}`}>
                 <h3 className={`font-semibold text-xl mb-4 flex items-center tracking-tight ${cls.textMain}`}>
                   <AlertTriangle className={`w-6 h-6 mr-3 ${cls.roseText}`} /> Catat Kesalahan Baru
                 </h3>
                 <form onSubmit={handleSaveErrorLog} className="space-y-4 font-medium text-sm">
                    {currentMultiplier > 1 && errorForm.pic && errorForm.deskripsi_kesalahan && (
                      <div className={`p-3 rounded-xl flex items-start border ${cls.roseCard} ${cls.roseText} leading-relaxed`}>
                        <AlertTriangle className="w-5 h-5 mr-2 mt-0.5 flex-shrink-0" />
                        <p className="text-xs">
                          <strong>Peringatan Multiplier:</strong> PIC <strong>{errorForm.pic.toUpperCase()}</strong> telah melakukan kesalahan yang sama sebanyak {currentMultiplier - 1} kali sebelumnya. Sistem akan mengalikan nominal kerugian sebesar <strong>{currentMultiplier}x Lipat</strong>!
                        </p>
                      </div>
                    )}

                    {/* Section Detail Order */}
                    <div className="space-y-3">
                      <div><label className={`text-xs block mb-1 font-semibold ${cls.textSub}`}>Tanggal Kejadian</label><input type="date" value={errorForm.tgl} onChange={e => setErrorForm({ ...errorForm, tgl: e.target.value })} className={`w-full p-3 ${cls.input}`} required /></div>
                      <div><label className={`text-xs block mb-1 font-semibold ${cls.textSub}`}>No. Invoice CRM</label><input type="text" value={errorForm.nomor_invoice} onChange={e => setErrorForm({ ...errorForm, nomor_invoice: e.target.value })} placeholder="Misal: INV-2026-001" className={`w-full p-3 ${cls.input}`} /></div>
                      <div className="grid grid-cols-2 gap-3">
                        <div><label className={`text-xs block mb-1 font-semibold ${cls.textSub}`}>Nama Konsumen</label><input type="text" value={errorForm.nama_konsumen} onChange={e => setErrorForm({ ...errorForm, nama_konsumen: e.target.value })} placeholder="Konsumen" className={`w-full p-3 ${cls.input}`} required /></div>
                        <div><label className={`text-xs block mb-1 font-semibold ${cls.textSub}`}>Nama Produk</label><input type="text" value={errorForm.nama_produk} onChange={e => setErrorForm({ ...errorForm, nama_produk: e.target.value })} placeholder="Nama Produk" className={`w-full p-3 ${cls.input}`} required /></div>
                      </div>
                    </div>

                    {/* Section Analisis Error */}
                    <div className="space-y-3 border-t border-black/5 dark:border-white/5 pt-3">
                      <div className="grid grid-cols-2 gap-3">
                        <div><label className={`text-xs block mb-1 font-semibold ${cls.textSub}`}>Kategori</label><select value={errorForm.kategori_kesalahan} onChange={e => setErrorForm({ ...errorForm, kategori_kesalahan: e.target.value })} className={`w-full p-3 ${cls.input}`}><option value="Kesesuaian/Ketepatan">Kesesuaian</option><option value="Kualitas">Kualitas</option><option value="Desain">Desain</option><option value="Bahan">Bahan</option><option value="Prosedur/Proses">Prosedur</option></select></div>
                        <div><label className={`text-xs block mb-1 font-semibold ${cls.textSub}`}>Jenis</label><select value={errorForm.jenis_kesalahan} onChange={e => setErrorForm({ ...errorForm, jenis_kesalahan: e.target.value })} className={`w-full p-3 ${cls.input}`}><option value="Machine Error">Mesin</option><option value="Human Error">Human</option><option value="Print Test">Tes Print</option></select></div>
                      </div>
                      <div className="grid grid-cols-2 gap-3">
                        <div><label className={`text-xs block mb-1 font-semibold ${cls.textSub}`}>Qty Rusak (Pcs)</label><input type="number" value={errorForm.qty_kesalahan} onChange={e => setErrorForm({ ...errorForm, qty_kesalahan: e.target.value })} placeholder="0" className={`w-full p-3 ${cls.input}`} required /></div>
                        <div><label className={`text-xs block mb-1 font-semibold ${cls.textSub}`}>PIC Terlibat</label><input type="text" value={errorForm.pic} onChange={e => setErrorForm({ ...errorForm, pic: e.target.value })} placeholder="Nama PIC" className={`w-full p-3 ${cls.input}`} required /></div>
                      </div>
                      <div className="grid grid-cols-2 gap-3">
                        <div><label className={`text-xs block mb-1 font-semibold ${cls.roseText}`}>Rugi Bahan (Rp)</label><input type="number" value={errorForm.kerugian_bahan} onChange={e => setErrorForm({ ...errorForm, kerugian_bahan: e.target.value })} placeholder="0" className={`w-full p-3 ${cls.input}`} /></div>
                        <div><label className={`text-xs block mb-1 font-semibold ${cls.roseText}`}>Rugi Jasa (Rp)</label><input type="number" value={errorForm.kerugian_jasa} onChange={e => setErrorForm({ ...errorForm, kerugian_jasa: e.target.value })} placeholder="0" className={`w-full p-3 ${cls.input}`} /></div>
                      </div>
                    </div>

                    {/* Section Evaluasi & Input Terpisah Baru */}
                    <div className="space-y-3 border-t border-black/5 dark:border-white/5 pt-3">
                      <div>
                        <label className={`text-xs block mb-1 font-semibold ${cls.textSub}`}>1. Deskripsi Kesalahan</label>
                        <textarea value={errorForm.deskripsi_kesalahan} onChange={e => setErrorForm({ ...errorForm, deskripsi_kesalahan: e.target.value })} className={`w-full p-3 ${cls.input}`} rows="2" placeholder="Ceritakan detail kronologi kesalahan yang terjadi..." required></textarea>
                      </div>
                      <div>
                        <label className={`text-xs block mb-1 font-semibold ${cls.textSub}`}>2. Penyebab Kesalahan</label>
                        <textarea value={errorForm.penyebab} onChange={e => setErrorForm({ ...errorForm, penyebab: e.target.value })} className={`w-full p-3 ${cls.input}`} rows="2" placeholder="Analisis mengapa kesalahan tersebut bisa terjadi..." required></textarea>
                      </div>
                      <div>
                        <label className={`text-xs block mb-1 font-semibold ${cls.textSub}`}>3. Solusi & Pencegahan</label>
                        <textarea value={errorForm.pencegahan_solusi} onChange={e => setErrorForm({ ...errorForm, pencegahan_solusi: e.target.value })} className={`w-full p-3 ${cls.input}`} rows="2" placeholder="Langkah pencegahan konkrit agar tidak terulang kembali..." required></textarea>
                      </div>
                      <div>
                        <label className={`text-xs block mb-1 font-semibold ${cls.textSub}`}>4. Penyelesaian Untuk Konsumen</label>
                        <input type="text" value={errorForm.penyelesaian} onChange={e => setErrorForm({ ...errorForm, penyelesaian: e.target.value })} placeholder="Misal: Cetak ulang gratis, refund 50%, dll." className={`w-full p-3 ${cls.input}`} required />
                      </div>
                    </div>

                    <button type="submit" className={`w-full py-4 rounded-[16px] font-bold transition-all shadow-sm ${cls.roseBg} hover:opacity-90 text-white mt-4 flex items-center justify-center`}>
                      <CheckCircle className="w-5 h-5 mr-2" /> Simpan Log Error
                    </button>
                 </form>
               </div>

               {/* SISI KANAN (3/5): Daftar & Laporan Log Kesalahan Real-time */}
               <div className={`xl:col-span-3 ${cls.cardBg} overflow-hidden`}>
                 <div className={`p-5 border-b flex justify-between items-center ${cls.tableDiv}`}>
                   <h3 className={`font-semibold text-lg flex items-center tracking-tight ${cls.textMain}`}>
                     <FileWarning className={`w-5 h-5 mr-2 ${cls.roseText}`} /> Riwayat Log Kesalahan
                   </h3>
                   <div className={`px-4 py-1.5 rounded-full text-xs font-bold ${cls.roseIcon}`}>
                     Kerugian: Rp {totalRupiahKerugian.toLocaleString('id-ID')}
                   </div>
                 </div>

                 <div className="overflow-x-auto overflow-y-auto max-h-[640px]">
                    <table className="w-full text-left text-[14px] whitespace-nowrap">
                      <thead className={`sticky top-0 z-10 border-b ${cls.tableHead}`}>
                        <tr>
                          <th className="px-5 py-3 font-semibold">Tanggal</th>
                          <th className="px-5 py-3 font-semibold">Konsumen &amp; Produk</th>
                          <th className="px-5 py-3 text-right font-semibold">Total Rugi (Rp)</th>
                          <th className="px-5 py-3 text-center font-semibold">Aksi</th>
                        </tr>
                      </thead>
                      <tbody className={`divide-y ${cls.tableDiv}`}>
                        {reportErrors.map(item => {
                          const baseLoss = Number(item.kerugian_bahan) + Number(item.kerugian_jasa);
                          const isPunished = baseLoss > 0 && Number(item.jumlah_kerugian) > baseLoss;
                          const mult = isPunished ? Math.round(Number(item.jumlah_kerugian) / baseLoss) : 1;

                          return (
                            <tr key={item.id} className={`${cls.tableRow}`}>
                              <td className={`px-5 py-4 font-medium ${cls.textSub}`}>{formatDateToLocale(item.tgl)}</td>
                              <td className="px-5 py-4">
                                <div className={`font-bold ${cls.textMain}`}>{item.nama_konsumen}</div>
                                <div className={`text-xs ${cls.textSub} mt-0.5 truncate max-w-[180px]`}>{item.nama_produk}</div>
                              </td>
                              <td className="px-5 py-4 text-right">
                                <div className={`font-mono font-bold ${isPunished ? cls.roseText : cls.textMain}`}>
                                  Rp {Number(item.jumlah_kerugian).toLocaleString('id-ID')}
                                </div>
                                {isPunished && (
                                  <span className={`text-[10px] font-bold px-1.5 py-0.5 rounded-full ${cls.roseIcon} inline-block mt-1`}>
                                    Penalty {mult}x
                                  </span>
                                )}
                              </td>
                              <td className="px-5 py-4 text-center">
                                <div className="flex items-center justify-center space-x-2">
                                  <button onClick={() => setErrorDetailModal({ isOpen: true, item: item })} className={`p-2 rounded-full ${cls.indigoIcon}`} title="Lihat Detail Investigasi">
                                    <Eye className="w-4 h-4" />
                                  </button>
                                  {isAdmin && (
                                    <>
                                      <button onClick={() => confirmEditError(item)} className={`p-2 rounded-full ${cls.indigoIcon}`} title="Edit Log">
                                        <Pencil className="w-4 h-4" />
                                      </button>
                                      <button onClick={() => confirmDelete(item, 'error')} className={`p-2 rounded-full ${cls.roseIcon}`} title="Hapus Log">
                                        <Trash2 className="w-4 h-4" />
                                      </button>
                                    </>
                                  )}
                                </div>
                              </td>
                            </tr>
                          );
                        })}
                        {reportErrors.length === 0 && (
                          <tr>
                            <td colSpan="4" className={`p-12 text-center font-medium ${cls.textMuted}`}>
                              Alhamdulillah, tidak ada catatan kesalahan pada periode ini.
                            </td>
                          </tr>
                        )}
                      </tbody>
                    </table>
                 </div>
               </div>

             </div>
          )}

          {/* TAB: REPORT (SEKARANG PURE HANYA PENCATATAN CLICK PEMAKAIAN MESIN) */}
          {activeTab === 'report' && (
             <div className={`overflow-hidden animate-in fade-in duration-500 p-6 md:p-8 ${cls.cardBg}`}>
               <div>
                 <div className={`px-2 py-2 mb-4 font-semibold text-lg tracking-tight ${cls.textMain}`}>Laporan Pemakaian Mesin (Click Log)</div>
                 <div className={`overflow-hidden border border-black/5 dark:border-white/5 rounded-[20px]`}>
                   <table className="w-full text-left text-[15px]">
                     <thead className={`border-b ${cls.tableHead}`}>
                       <tr>
                         <th className="px-5 py-3 font-semibold">Tgl Input</th>
                         <th className="px-5 py-3 font-semibold">Berlaku Untuk</th>
                         <th className="px-5 py-3 font-semibold">Operator</th>
                         <th className="px-5 py-3 text-right font-semibold">Pemakaian</th>
                       </tr>
                     </thead>
                     <tbody className={`divide-y ${cls.tableDiv}`}>
                       {reportClicks.map(item => (
                         <tr key={item.id} className={cls.tableRow}>
                           <td className={`px-5 py-3.5 ${cls.textSub}`}>{item.dateStr.split(' ')[0]}</td>
                           <td className={`px-5 py-3.5 font-semibold ${cls.textMain}`}>{item.dateFor}</td>
                           <td className={`px-5 py-3.5 font-medium ${cls.textSub}`}>{item.operator}</td>
                           <td className={`px-5 py-3.5 text-right font-mono font-bold ${cls.indigoText}`}>+{item.dailyClicks.toLocaleString()}</td>
                         </tr>
                       ))}
                     </tbody>
                   </table>
                 </div>
               </div>
             </div>
          )}

        </div>
      </div>

      {/* --- ADMIN PANEL KHUSUS PENGATURAN & EDIT/HAPUS --- */}
      {isAdmin && (
        <div className={`mt-12 overflow-hidden animate-in fade-in slide-in-from-bottom-8 duration-700 ${cls.cardBg}`}>
          <div className={`p-6 border-b flex items-center justify-between ${cls.tableDiv}`}>
            <div className={`flex items-center space-x-3 ${cls.textMain}`}>
              <div className={`p-2.5 rounded-[12px] ${cls.roseIcon}`}><Settings className="w-6 h-6" /></div>
              <h2 className="font-bold text-2xl tracking-tight">Admin Console</h2>
            </div>
            <span className={`text-[10px] font-bold uppercase tracking-widest px-3 py-1.5 rounded-full ${cls.roseIcon}`}>Superuser</span>
          </div>

          <div className="p-6 md:p-8 space-y-8">

            <div className={`p-6 rounded-[20px] flex flex-col md:flex-row items-center justify-between gap-6 border ${cls.tableDiv}`}>
              <div>
                <h3 className={`font-semibold text-lg tracking-tight ${cls.textMain}`}>Tarif Per Klik</h3>
                <p className={`text-sm mt-1 font-medium ${cls.textSub}`}>Digunakan untuk kalkulasi estimasi pendapatan di Dashboard.</p>
              </div>
              <form onSubmit={handleUpdatePrice} className="flex items-center space-x-3 w-full md:w-auto">
                <input type="number" value={adminPriceInput} onChange={(e) => setAdminPriceInput(e.target.value)} className={`w-32 px-4 py-3 font-mono font-bold text-lg ${cls.input}`} placeholder="Rp" />
                <button type="submit" className={`py-3 px-6 font-semibold text-white rounded-full ${cls.indigoBg} hover:opacity-90 transition-opacity`}>Simpan</button>
              </form>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">

              <div className={`border rounded-[20px] overflow-hidden flex flex-col ${cls.tableDiv}`}>
                <div className={`px-5 py-4 font-semibold border-b text-[15px] flex items-center ${cls.textMain} ${cls.tableDiv}`}><Printer className={`w-5 h-5 mr-3 ${cls.indigoText}`} /> Edit/Hapus Klik</div>
                <div className={`overflow-y-auto h-[350px] bg-transparent`}>
                  {sortedHistory.slice().reverse().map(h => (
                    <div key={h.id} className={`flex justify-between items-center p-4 border-b text-[15px] ${cls.tableRow} ${cls.tableDiv}`}>
                      <div className="truncate"><div className={`font-semibold ${cls.textMain}`}>{h.dateFor}</div><div className={`text-sm font-medium ${cls.textSub}`}>{h.totalClicks.toLocaleString()}k</div></div>
                      <div className="flex space-x-2">
                        <button onClick={() => confirmEditClick(h)} className={`p-2 rounded-full ${cls.indigoIcon}`}><Pencil className="w-4 h-4" /></button>
                        <button onClick={() => confirmDelete(h, 'click')} className={`p-2 rounded-full ${cls.roseIcon}`}><Trash2 className="w-4 h-4" /></button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              <div className={`border rounded-[20px] overflow-hidden flex flex-col ${cls.tableDiv}`}>
                <div className={`px-5 py-4 font-semibold border-b text-[15px] flex items-center ${cls.textMain} ${cls.tableDiv}`}><ShoppingCart className={`w-5 h-5 mr-3 ${cls.amberText}`} /> Edit/Hapus Pembelian</div>
                <div className={`overflow-y-auto h-[350px] bg-transparent`}>
                  {purchases.map(h => (
                    <div key={h.id} className={`flex justify-between items-center p-4 border-b text-[15px] ${cls.tableRow} ${cls.tableDiv}`}>
                      <div className="truncate pr-2"><div className={`font-semibold truncate ${cls.textMain}`}>{h.part_name} <span className={cls.emeraldText}>(+{h.qty})</span></div><div className={`text-sm font-medium ${cls.textSub}`}>{formatDateToLocale(h.tgl_pembelian)}</div></div>
                      <div className="flex space-x-2">
                         <button onClick={() => confirmEditPurchase(h)} className={`p-2 rounded-full ${cls.indigoIcon}`}><Pencil className="w-4 h-4" /></button>
                         <button onClick={() => confirmDelete(h, 'purchase')} className={`p-2 rounded-full ${cls.roseIcon}`}><Trash2 className="w-4 h-4" /></button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              <div className={`border rounded-[20px] overflow-hidden flex flex-col ${cls.tableDiv}`}>
                <div className={`px-5 py-4 font-semibold border-b text-[15px] flex items-center ${cls.textMain} ${cls.tableDiv}`}><Package className={`w-5 h-5 mr-3 ${cls.emeraldText}`} /> Edit/Hapus Stok</div>
                <div className={`overflow-y-auto h-[350px] bg-transparent`}>
                  {inventory.map(h => (
                    <div key={h.id} className={`flex justify-between items-center p-4 border-b text-[15px] ${cls.tableRow} ${cls.tableDiv}`}>
                      <div className="truncate pr-2"><div className={`font-semibold truncate ${cls.textMain}`}>{h.part_name}</div><div className={`text-sm font-medium ${cls.textSub}`}>{h.stock} Pcs</div></div>
                      <div className="flex space-x-2">
                         <button onClick={() => confirmEditInventory(h)} className={`p-2 rounded-full ${cls.indigoIcon}`}><Pencil className="w-4 h-4" /></button>
                         <button onClick={() => confirmDelete(h, 'inventory')} className={`p-2 rounded-full ${cls.roseIcon}`}><Trash2 className="w-4 h-4" /></button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              <div className={`border rounded-[20px] overflow-hidden flex flex-col ${cls.tableDiv}`}>
                <div className={`px-5 py-4 font-semibold border-b text-[15px] flex items-center ${cls.textMain} ${cls.tableDiv}`}><AlertTriangle className={`w-5 h-5 mr-3 ${cls.roseText}`} /> Edit/Hapus Error</div>
                <div className={`overflow-y-auto h-[350px] bg-transparent`}>
                  {errorLogs.map(h => (
                    <div key={h.id} className={`flex justify-between items-center p-4 border-b text-[15px] ${cls.tableRow} ${cls.tableDiv}`}>
                      <div className="truncate pr-2"><div className={`font-semibold truncate ${cls.textMain}`}>{h.nama_konsumen}</div><div className={`text-sm font-medium ${cls.textSub}`}>{formatDateToLocale(h.tgl)}</div></div>
                      <div className="flex space-x-2">
                        <button onClick={() => confirmEditError(h)} className={`p-2 rounded-full ${cls.indigoIcon}`}><Pencil className="w-4 h-4" /></button>
                        <button onClick={() => confirmDelete(h, 'error')} className={`p-2 rounded-full ${cls.roseIcon}`}><Trash2 className="w-4 h-4" /></button>
                      </div>
                    </div>
                  ))}
                </div>
              </div>

              <div className={`border rounded-[20px] overflow-hidden flex flex-col ${cls.tableDiv}`}>
                <div className={`px-5 py-4 font-semibold border-b text-[15px] flex items-center ${cls.textMain} ${cls.tableDiv}`}><RotateCcw className={`w-5 h-5 mr-3 ${cls.amberText}`} /> Hapus Reset Part</div>
                <div className={`overflow-y-auto h-[350px] bg-transparent`}>
                  {replacementHistory.slice().reverse().map(h => (
                    <div key={h.id} className={`flex justify-between items-center p-4 border-b text-[15px] ${cls.tableRow} ${cls.tableDiv}`}>
                      <div className="truncate pr-2"><div className={`font-semibold truncate ${cls.amberText}`}>{h.partName}</div><div className={`text-sm font-medium ${cls.textSub}`}>{formatDateToLocale(h.createdAt)}</div></div>
                      <button onClick={() => confirmDelete(h, 'part')} className={`p-2 rounded-full flex-shrink-0 ${cls.roseIcon}`}><Trash2 className="w-4 h-4" /></button>
                    </div>
                  ))}
                </div>
              </div>

            </div>
          </div>
        </div>
      )}

      {/* Footer Info */}
      <div className={`mt-10 text-center text-sm font-medium flex justify-center items-center ${cls.textMuted}`}>
        <Info className="w-4 h-4 mr-2" /> Konica Tracker ERP &bull; Disinkronisasi via Supabase
      </div>

    </div>
  );
}
