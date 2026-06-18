import { create } from 'zustand';
import axios from 'axios';


const API_URL = 'http://127.0.0.1:8000/api';

// 1. Instanciar Axios con la configuración estática base
const apiInstance = axios.create({ 
  baseURL: API_URL,
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  }
});

// ⚡ INTERCEPTOR DEFINITIVO: Inserta el Token asíncronamente antes de salir la petición
apiInstance.interceptors.request.use(
  (config) => {
    const token = localStorage.getItem('token');
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }
    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

const useStore = create((set, get) => ({
  // --- ESTADO DE SESIÓN ---
  user: JSON.parse(localStorage.getItem('user')) || null,
  token: localStorage.getItem('token') || null,

  // --- ESTADO DE DATOS ---
  productos: [],
  historialVersiones: [],
  formulas: {}, 
  tcActual: 18.00,
  notification: null,
  isInitializing: false, // Candado de control de flujo
  
  // Instancia blindada compartida en todo el Store
  api: apiInstance,

  // --- ACCIONES DE AUTENTICACIÓN ---
  login: async (credentials) => {
    try {
      const res = await get().api.post('/login', credentials);
      const { token, user } = res.data;

      localStorage.setItem('token', token);
      localStorage.setItem('user', JSON.stringify(user));

      set({ token, user });
      
      // Inicializar el flujo maestro con la certeza del interceptor activo
      await get().initialize(); 
      
      return { success: true };
    } catch (error) {
      return { 
        success: false, 
        message: error.response?.data?.message || "Error al iniciar sesión" 
      };
    }
  },

  logout: () => {
    try {
      get().api.post('/logout');
    } catch (e) {
      console.log("Token local revocado o expirado");
    } finally {
      localStorage.removeItem('token');
      localStorage.removeItem('user');
      set({ 
        user: null, 
        token: null, 
        productos: [], 
        historialVersiones: [],
        formulas: {}
      });
      window.location.href = '/'; 
    }
  },

  // --- ACCIONES INICIALES OPTIMIZADAS (CON SEGURO ANTIDUPLICADO) ---
  initialize: async () => {
    // 🛡️ CONTROL DE FLUJO: Si no hay token o ya hay un proceso de red corriendo, abortamos inmediatamente
    if (!get().token || get().isInitializing) return;
    
    // Si ya tenemos los productos cargados en memoria, evitamos consultar la red otra vez
    if (get().productos.length > 0) return;

    // Activamos el candado
    set({ isInitializing: true });

    try {
      // Las llamadas viajan en paralelo de un solo golpe
      const [prodsRes, tcRes] = await Promise.all([
        get().api.get('/productos'),
        get().api.get('/tipo-cambio/actual')
      ]);

      set({
        productos: prodsRes.data || [],
        tcActual: Number(tcRes.data?.valor) || 18.00,
        isInitializing: false // Abrimos candado
      });
    } catch (error) {
      set({ isInitializing: false }); // Liberamos el proceso en caso de error
      if (error.response?.status === 401) {
        get().logout();
      }
      console.error("Error en inicialización:", error.response?.data || error);
    }
  },

  // --- CATÁLOGO DE PRODUCTOS RECOBRADO RECOGNITIVO (0% CONSULTAS REDUNDANTES) ---
  addProducto: async (data) => {
    try {
      const res = await get().api.post('/productos', data);
      
      // 🚀 REACTIVIDAD EN RAM: Construimos el objeto insertado y lo inyectamos directamente al estado local
      const nuevoProducto = {
        id_producto: res.data.id_producto,
        clave_producto: data.clave_producto,
        descripcion_producto: data.descripcion_producto,
        tipo_producto: data.tipo_producto,
        unidad_producto: data.unidad_producto || 'kg',
        familia_producto: data.familia_producto || null,
        costo: Number(data.costo) || 0,
        moneda: data.moneda || 'MXN'
      };

      set((state) => ({
        productos: [...state.productos, nuevoProducto]
      }));

      get().setNotification({ message: "Producto registrado con éxito", type: "success" });
      return res.data;
    } catch (error) {
      console.error("Error al añadir producto:", error);
      throw error;
    }
  },

  updateProducto: async (id, data) => {
    try {
      await get().api.put(`/productos/${id}`, data);
      
      // 🚀 ACTUALIZACIÓN LOCAL EN RAM: Modificamos el objeto del arreglo directamente en memoria
      set((state) => ({
        productos: state.productos.map((p) => 
          p.id_producto === Number(id) 
            ? { ...p, ...data, costo: Number(data.costo) || 0 } 
            : p
        )
      }));

      get().setNotification({ message: "Producto actualizado en catálogo", type: "success" });
    } catch (error) {
      console.error("Error al actualizar producto:", error);
      throw error;
    }
  },

  deleteProducto: async (id) => {
    try {
      await get().api.delete(`/productos/${id}`);
      set(state => ({
        productos: state.productos.filter(p => p.id_producto !== id)
      }));
      get().setNotification({ message: "Producto eliminado", type: "success" });
    } catch (error) {
      get().setNotification({ 
        message: "No se puede eliminar: tiene fórmulas asociadas", 
        type: "error" 
      });
    }
  },

  // --- TIPO DE CAMBIO MASIVO ---
  actualizarTipoCambio: async (valor) => {
    try {
      set({ notification: { message: "Actualizando precios masivamente...", type: "info" } });
      await get().api.post('/tipo-cambio/actualizar-masivo', { valor }); 
      
      // Reseteamos localmente para forzar una sincronización limpia
      set({ productos: [], tcActual: Number(valor) }); 
      await get().initialize();
      
      get().setNotification({ 
        message: `TC actualizado a $${valor}. Precios recalculados masivamente.`, 
        type: "success" 
      });
    } catch (error) {
      get().setNotification({ message: "Error en actualización masiva", type: "error" });
    }
  },

  // --- MOTOR DE FÓRMULAS Y VERSIONES ---
  loadVersiones: async (id_producto) => {
    try {
      const res = await get().api.get(`/formulas/${id_producto}`); 
      set(state => ({
        formulas: { ...state.formulas, [id_producto]: res.data }
      }));
      return res.data;
    } catch (error) {
      console.error("Error al cargar versiones:", error);
    }
  },

  loadHistorialCompleto: async () => {
    try {
      const res = await get().api.get('/formulas/historial/todos'); 
      set({ historialVersiones: res.data || [] });
    } catch (error) {
      console.error("Error al cargar historial:", error);
      set({ historialVersiones: [] });
    }
  },

  createVersionFormula: async (data) => {
    try {
      const res = await get().api.post('/formulas', data);
      await get().loadVersiones(data.id_producto);
      
      set({ productos: [] }); 
      await get().initialize(); 
      
      get().setNotification({ 
        message: "Versión v" + res.data.numero_version + " guardada exitosamente", 
        type: "success" 
      });
      return res.data;
    } catch (error) {
      get().setNotification({ message: "Error al guardar la nueva receta", type: "error" });
      throw error;
    }
  },

  getUltimosIngredientes: async (id_producto) => {
    try {
      const resVersion = await get().api.get(`/formulas/${id_producto}/ultima`); 
      const ultimaVersion = resVersion.data;
      if (!ultimaVersion) return null;

      const resIngredientes = await get().api.get(`/formulas/version/${ultimaVersion.id_version}/ingredientes`); 
      return {
        version: ultimaVersion,
        ingredients: resIngredientes.data
      };
    } catch (error) {
      console.error("Error al cargar formula previa:", error);
      return null;
    }
  },

  actualizarVersionActual: async (data) => {
    try {
      const res = await get().api.put(`/formulas/${data.id_producto}`, data); 
      set({ productos: [] });
      await get().initialize(); 
      
      get().setNotification({ 
        message: "Fórmula corregida y costo actualizado", 
        type: "success" 
      });
      return res.data;
    } catch (error) {
      get().setNotification({ message: "Error al actualizar la fórmula", type: "error" });
      throw error;
    }
  },

  fetchReporteVersion: async (id_version) => {
    try {
      const res = await get().api.get(`/formulas/reporte/${id_version}`); 
      return res.data;
    } catch (error) {
      console.error("Error al obtener reporte:", error);
      return null;
    }
  },

  // --- MOTOR DE COSTOS POR LOTE (BATCH) ---
  getCostosBatch: async (ids) => {
    try {
      if (!ids || ids.length === 0) return {};
      const res = await get().api.post('/productos/costos-batch', { ids });
      return res.data; 
    } catch (error) {
      console.error("Error en la solicitud por lote de costos:", error);
      return {};
    }
  },

  setNotification: (notification) => set({ notification }),
  clearNotification: () => set({ notification: null }),
}));

export default useStore;