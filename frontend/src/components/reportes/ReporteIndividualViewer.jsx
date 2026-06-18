import React, { useState, useEffect, useMemo } from 'react';
import useStore from '../../store/useStore';
import { FileText, Loader2, FileDown, Search, Plus, Trash2, Layers } from 'lucide-react';
import jsPDF from 'jspdf';
import html2canvas from 'html2canvas';

const ReporteIndividualViewer = () => {
  const { productos, fetchReporteVersion, loadVersiones } = useStore();

  const [searchTerm, setSearchTerm] = useState('');
  const [selectedProductId, setSelectedProductId] = useState('');
  const [versiones, setVersiones] = useState([]);
  const [selectedVersionId, setSelectedVersionId] = useState('');
  const [loading, setLoading] = useState(false);
  const [isExporting, setIsExporting] = useState(false);

  // 🛒 ESTADO PARA EL CARRITO DE REPORTES MULTIPLES
  const [listaDescarga, setListaDescarga] = useState([]);

  // Filtrado dinámico de la lista de productos
  const piList = useMemo(() => {
    return productos.filter(p => {
      const cumpleTipo = p.tipo_producto === 'PI';
      if (!searchTerm) return cumpleTipo;

      const term = searchTerm.toLowerCase();
      const cumpleClave = p.clave_producto?.toLowerCase().includes(term);
      const cumpleDescripcion = p.descripcion_producto?.toLowerCase().includes(term);

      return cumpleTipo && (cumpleClave || cumpleDescripcion);
    });
  }, [productos, searchTerm]);

  // Cargar versiones cuando cambia el producto seleccionado
  useEffect(() => {
    const getVersions = async () => {
      if (selectedProductId) {
        const data = await loadVersiones(selectedProductId);
        setVersiones(data || []);
      } else {
        setVersiones([]);
        setSelectedVersionId('');
      }
    };
    getVersions();
  }, [selectedProductId, loadVersiones]);

  // 📥 AÑADIR REPORTE AL BUNDLE DE DESCARGA
  const agregarALista = async () => {
    if (!selectedVersionId) return;

    // Verificar si ya está agregado para no duplicar la misma versión exacta
    if (listaDescarga.some(item => item.id_version === selectedVersionId)) {
      alert("Esta versión de reporte ya está en la lista de descarga.");
      return;
    }

    setLoading(true);
    try {
      const data = await fetchReporteVersion(selectedVersionId);
      if (data) {
        setListaDescarga([...listaDescarga, {
          id_version: selectedVersionId,
          version_numero: versiones.find(v => v.id_version === parseInt(selectedVersionId))?.numero_version || 1,
          ...data
        }]);
      }
    } catch (error) {
      console.error("Error al precargar reporte:", error);
    } finally {
      setLoading(false);
    }
  };

  // 🗑️ QUITAR REPORTE DE LA LISTA
  const eliminarDeLista = (idVersion) => {
    setListaDescarga(listaDescarga.filter(item => item.id_version !== idVersion));
  };

  // 🚀 GENERAR UN SOLO PDF CON TODOS LOS REPORTES (UNO POR PÁGINA)
  const downloadBulkPDF = async () => {
    if (listaDescarga.length === 0) return;

    setIsExporting(true);
    try {
      const pdf = new jsPDF('l', 'mm', 'a4');
      const pdfWidth = pdf.internal.pageSize.getWidth();
      const pdfHeight = pdf.internal.pageSize.getHeight();

      // Recorremos secuencialmente los contenedores ocultos que renderizaremos abajo
      for (let i = 0; i < listaDescarga.length; i++) {
        const item = listaDescarga[i];
        const element = document.getElementById(`reporte-render-${item.id_version}`);

        if (element) {
          const canvas = await html2canvas(element, {
            scale: 2,
            useCORS: true,
            backgroundColor: "#ffffff",
            logging: false,
            onclone: (clonedDoc) => {
              const el = clonedDoc.getElementById(`reporte-render-${item.id_version}`);
              if (el) {
                el.style.fontFamily = 'Arial, sans-serif';
                el.style.backgroundColor = '#ffffff';
                const allElements = el.querySelectorAll('*');
                allElements.forEach(subEl => {
                  const compStyle = clonedDoc.defaultView.getComputedStyle(subEl);
                  if (compStyle.color.includes('oklch')) subEl.style.color = '#000000';
                  if (compStyle.backgroundColor.includes('oklch')) subEl.style.backgroundColor = 'transparent';
                });
              }
            }
          });

          const imgData = canvas.toDataURL('image/png');
          const imgHeight = (canvas.height * pdfWidth) / canvas.width;
          const positionY = Math.max(0, (pdfHeight - imgHeight) / 2);

          // Si no es la primera página, añadimos una nueva hoja en blanco al PDF
          if (i > 0) {
            pdf.addPage('l', 'mm', 'a4');
          }

          pdf.addImage(imgData, 'PNG', 0, positionY, pdfWidth, imgHeight);
        }
      }

      pdf.save(`Compilado_Reportes_Tensoquimia_${getFormattedDate().replace(/\//g, '-')}.pdf`);
    } catch (error) {
      console.error("Error al compilar PDF múltiple:", error);
      alert("Ocurrió un error al unificar los archivos.");
    } finally {
      setIsExporting(false);
    }
  };

  const getFormattedDate = () => {
    const hoy = new Date();
    const dia = String(hoy.getDate()).padStart(2, '0');
    const mes = String(hoy.getMonth() + 1).padStart(2, '0');
    const anio = hoy.getFullYear();
    return `${dia}/${mes}/${anio}`;
  };

  return (
    <div className="space-y-6">
      {/* SECCIÓN DE SELECCIÓN Y AGREGADO */}
      <div className="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
        <h1 className="text-xl font-black text-slate-800 uppercase italic mb-6 flex items-center gap-3">
          <Layers className="text-emerald-500" /> Compilador de Reportes Múltiples
        </h1>
        
        <div className="mb-4 relative">
          <div className="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
            <Search className="h-4 w-4 text-slate-400" />
          </div>
          <input
            type="text"
            className="w-full pl-10 p-3 border border-slate-300 rounded-xl font-bold text-xs uppercase outline-none focus:ring-2 ring-emerald-500"
            placeholder="Filtrar por nombre o clave de producto..."
            value={searchTerm}
            onChange={(e) => setSearchTerm(e.target.value)}
          />
        </div>

        <div className="grid grid-cols-1 md:grid-cols-2 gap-4 items-center">
          <select 
            className="p-3 border border-slate-300 rounded-xl font-bold text-xs uppercase outline-none focus:ring-2 ring-emerald-500"
            value={selectedProductId}
            onChange={(e) => setSelectedProductId(e.target.value)}
          >
            <option value="">-- SELECCIONAR PRODUCTO ({piList.length}) --</option>
            {piList.map(p => (
              <option key={p.id_producto} value={p.id_producto}>
                {p.clave_producto} - {p.descripcion_producto}
              </option>
            ))}
          </select>

          <div className="flex gap-2">
            <select 
              className="p-3 border border-slate-300 rounded-xl font-bold text-xs disabled:opacity-50 outline-none focus:ring-2 ring-emerald-500 flex-1"
              value={selectedVersionId}
              onChange={(e) => setSelectedVersionId(e.target.value)}
              disabled={!selectedProductId}
            >
              <option value="">-- SELECCIONAR VERSIÓN --</option>
              {versiones.map(v => (
                <option key={v.id_version} value={v.id_version}>
                  v{v.numero_version} - {new Date(v.fecha).toLocaleDateString()}
                </option>
              ))}
            </select>

            <button
              onClick={agregarALista}
              disabled={!selectedVersionId || loading}
              className="bg-slate-800 text-white p-3 rounded-xl font-bold hover:bg-slate-700 disabled:opacity-50 transition-all flex items-center gap-2 text-xs uppercase"
            >
              {loading ? <Loader2 className="animate-spin" size={16}/> : <Plus size={16}/>}
              Añadir
            </button>
          </div>
        </div>
      </div>

      {/* 🛒 LISTA DE ESPERA / COLA DE DESCARGA */}
      {listaDescarga.length > 0 && (
        <div className="bg-white p-6 rounded-xl border border-slate-200 shadow-sm">
          <h2 className="text-sm font-black text-slate-700 uppercase tracking-wider mb-4 flex items-center gap-2">
            Documentos en la cola ({listaDescarga.length})
          </h2>
          <div className="divide-y divide-slate-100 max-h-60 overflow-y-auto mb-6">
            {listaDescarga.map((item) => (
              <div key={item.id_version} className="py-3 flex justify-between items-center text-xs font-bold uppercase">
                <div className="flex items-center gap-3">
                  <span className="bg-emerald-100 text-emerald-800 px-2 py-1 rounded font-mono">{item.master.clave_producto}</span>
                  <span className="text-slate-600 font-normal truncate max-w-md">{item.master.descripcion_producto}</span>
                  <span className="bg-slate-100 text-slate-700 px-2 py-0.5 rounded text-[10px]">v{item.version_numero}</span>
                </div>
                <button 
                  onClick={() => eliminarDeLista(item.id_version)}
                  className="text-red-500 hover:text-red-700 p-2 transition-all"
                >
                  <Trash2 size={16} />
                </button>
              </div>
            ))}
          </div>

          <button
            onClick={downloadBulkPDF}
            disabled={isExporting}
            className="w-full flex items-center justify-center gap-3 bg-emerald-500 text-white py-4 rounded-xl font-black uppercase text-xs tracking-[0.2em] hover:bg-emerald-600 transition-all shadow-xl shadow-emerald-100 disabled:opacity-50"
          >
            {isExporting ? <Loader2 className="animate-spin" size={18}/> : <FileDown size={18} />}
            {isExporting ? 'Unificando y Renderizando PDF...' : 'Descargar Todo en Un Solo PDF'}
          </button>
        </div>
      )}

      {/* 🖨️ CONTENEDORES OCULTOS PARA RENDERIZAR EN EL CANVAS */}
      <div className="absolute left-[-9999px] top-0 bg-slate-900 pointer-events-none">
        {listaDescarga.map((reportData) => (
          <div 
            key={reportData.id_version}
            id={`reporte-render-${reportData.id_version}`} 
            className="bg-white p-[15mm] w-[297mm] mb-10"
            style={{ color: '#000000', backgroundColor: '#ffffff' }}
          >
            <style>{`
              .excel-table { border: 2px solid #000000; width: 100%; border-collapse: collapse; background-color: #ffffff; }
              .excel-table th, .excel-table td { border: 1px solid #000000; padding: 6px 8px; font-size: 11px; color: #000000 !important; }
              .excel-table th { font-weight: bold; background-color: #ffffff; }
              .bg-gray-excel { background-color: #f2f2f2 !important; }
              .bg-green-excel { background-color: #92d050 !important; }
              .text-blue-excel { color: #1e40af !important; }
            `}</style>

            <div className="space-y-4 font-sans relative">
              <div className="absolute top-0 left-0 text-[10px] font-bold text-slate-600 uppercase">
                Fecha de Emisión: {getFormattedDate()}
              </div>

              <h2 className="text-center font-bold text-sm uppercase mb-6" style={{ color: '#000000' }}>
                Tabla de Operaciones por Producto
              </h2>

              <div className="flex justify-end items-center gap-4 text-[11px] font-bold">
                <span style={{ color: '#000000' }}>TIPO DE CAMBIO</span>
                <div className="border border-black px-4 py-1 w-24 text-center" style={{ color: '#000000' }}>$ {Number(reportData.master.tc_valor).toFixed(2)}</div>
              </div>

              <div className="flex items-stretch text-[12px] font-bold" style={{ color: '#000000' }}>
                <div className="border-2 border-black p-2 w-28 text-center">{reportData.master.clave_producto}</div>
                <div className="border-2 border-black border-l-0 p-2 flex-1 uppercase">{reportData.master.descripcion_producto}</div>
                <div className="border-2 border-black border-l-0 bg-green-excel p-2 w-16 text-center">$</div>
                <div className="border-2 border-black border-l-0 bg-green-excel p-2 w-28 text-right">
                  {Number(reportData.master.costo_final).toFixed(2)}
                </div>
                <div className="border-2 border-black border-l-0 bg-gray-excel p-2 w-10 text-center text-[10px]">M.N</div>
                <div className="border-2 border-black border-l-0 bg-gray-excel p-2 w-24 text-right">
                  $ {Number(reportData.master.costo_final / (reportData.master.tc_valor || 1)).toFixed(2)}
                </div>
                <div className="border-2 border-black border-l-0 bg-gray-excel p-2 w-10 text-center text-[10px]">USD</div>
              </div>

              <table className="excel-table">
                <thead>
                  <tr>
                    <th className="w-24">Clave</th>
                    <th className="w-24 text-center">Porcentaje</th>
                    <th>Descripción</th>
                    <th className="w-24">Costo</th>
                    <th className="w-16">Moneda</th>
                    <th className="w-24">Costo Pesos</th>
                    <th className="w-28 bg-gray-excel text-center">Costo Final M.N</th>
                  </tr>
                </thead>
                <tbody>
                  {reportData.ingredientes.map((ing, idx) => {
                    const porc = Number(ing.porcentaje) || 0;
                    const cBase = Number(ing.costo_base_unitario) || 0;
                    const tc = Number(reportData.master.tc_valor) || 1;
                    const cPesos = ing.moneda_base === 'USD' ? cBase * tc : cBase;
                    const cFinal = cPesos * (porc / 100);

                    return (
                      <tr key={idx}>
                        <td className="text-center font-mono">{ing.clave_producto}</td>
                        <td className="text-center font-bold">{porc.toFixed(2)}%</td>
                        <td className="uppercase">{ing.descripcion_producto}</td>
                        <td className="text-right">$ {cBase.toFixed(2)}</td>
                        <td className="text-center">{ing.moneda_base}</td>
                        <td className="text-right" style={ing.moneda_base === 'USD' ? { color: '#059669', fontWeight: 'bold' } : { color: '#000000' }}>
                          $ {cPesos.toFixed(2)}
                        </td>
                        <td className="text-right font-bold bg-gray-excel">$ {cFinal.toFixed(2)}</td>
                      </tr>
                    );
                  })}
                </tbody>
              </table>

              <div className="flex justify-end pt-2">
                <div className="w-[480px]">
                  <table className="excel-table border-t-0">
                    <tbody>
                      <tr>
                        <td className="text-right font-bold uppercase w-48">Total %</td>
                        <td className="text-center font-bold w-24">100.00%</td>
                        <td className="bg-gray-excel font-bold text-center w-24 uppercase">Costo</td>
                        <td className="text-right font-bold w-12 text-[9px]">M.N.</td>
                        <td className="text-right font-bold w-24">
                          $ {Number(reportData.master.costo_final - reportData.master.factor_proceso).toFixed(2)}
                        </td>
                      </tr>
                      <tr>
                        <td colSpan={2} className="border-0"></td>
                        <td className="bg-gray-excel font-bold text-center italic text-blue-excel font-black">FP</td>
                        <td className="text-right font-bold text-[9px] text-blue-excel">$</td>
                        <td className="text-right font-bold text-blue-excel underline">
                          {Number(reportData.master.factor_proceso || 0).toFixed(2)}
                        </td>
                      </tr>
                      <tr className="border-t-2 border-black">
                        <td colSpan={2} className="border-0"></td>
                        <td className="bg-gray-excel font-bold text-center uppercase font-black">Costo Total</td>
                        <td className="text-right font-bold text-[9px]">M.N.</td>
                        <td className="text-right font-bold text-sm">$ {Number(reportData.master.costo_final).toFixed(2)}</td>
                      </tr>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        ))}
      </div>
    </div>
  );
};

export default ReporteIndividualViewer;