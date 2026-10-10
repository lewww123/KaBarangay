import { Head, useForm } from '@inertiajs/react';
import ClerkLayout from '@/layouts/ClerkLayout'; 
import { useState } from 'react';
import axios from 'axios';

interface User {
    id: number;
    name: string;
    email: string;
    role: string;
    staff_id: string;
    department: string;
}

const CERTIFICATE_FEES: Record<string, string> = {
    'Residency': '100',
    'Indigency': '50',
    'Delayed PSA Registration': '50',
    'Marriage Cohabitation': '100',
    'Scholarship Certificate': '50',
    'Tricycle': '300',
    'Sari-sari Store': '100',
};

const SIGNATORIES = [
    { name: 'HON. JENNIFER CALINGAO TRINIDAD', title: 'Punong Barangay' },
    { name: 'HON. MARIA CLARA', title: 'Barangay Kagawad' },
    { name: 'PEDRO PENDUKO', title: 'Barangay Secretary' },
];

export default function Dashboard({ auth }: { auth: { user: User } }) {
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [modalMode, setModalMode] = useState<'manual' | 'qr'>('manual');
    const [scanLoading, setScanLoading] = useState(false);
    const [formStep, setFormStep] = useState<1 | 2>(1);

    const { data, setData, processing, reset } = useForm({
        control_no: `CTRL-${new Date().getFullYear()}-${Math.floor(1000 + Math.random() * 9000)}`,
        resident_id: '',
        resident_name: '',
        purok: '',
        certificate_type: 'Residency',
        fee: '100',
        purpose: '',
        signatory_name: '',
        signatory_title: '',
        franchise_no: '',
        business_name: '',
        business_address: '',
    });

    const handleCertificateTypeChange = (type: string) => {
        setData(data => ({
            ...data,
            certificate_type: type,
            fee: CERTIFICATE_FEES[type] || '0',
            franchise_no: '',
            business_name: '',
            business_address: '',
        }));
    };

    const handleSignatoryChange = (selectedName: string) => {
        const signatory = SIGNATORIES.find(sig => sig.name === selectedName);
        setData(data => ({
            ...data,
            signatory_name: selectedName,
            signatory_title: signatory ? signatory.title : ''
        }));
    };

    const handleQRCodeScanned = async (scannedCode: string) => {
        setScanLoading(true);
        try {
            const response = await axios.get(`/clerk/residents/lookup?code=${scannedCode}`);
            if (response.data) {
                setData(data => ({
                    ...data,
                    resident_id: response.data.id,
                    resident_name: response.data.full_name,
                    purok: response.data.purok || data.purok,
                    purpose: response.data.default_purpose || data.purpose
                }));
                setModalMode('manual');
            }
        } catch (error) {
            setData(data => ({
                ...data,
                resident_id: 'RES-2026-001',
                resident_name: 'LEWIN LAGUISMA',
                purok: 'Purok Masagana',
            }));
            setModalMode('manual');
        } finally {
            setScanLoading(false);
        }
    };

    const handleProceedToReview = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault(); 
        setFormStep(2);
    };

    const handleFinalSubmit = () => {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/clerk/generate-certificate';
        
        const tokenMeta = document.querySelector('meta[name="csrf-token"]');
        if (tokenMeta) {
            const csrfInput = document.createElement('input');
            csrfInput.type = 'hidden';
            csrfInput.name = '_token';
            csrfInput.value = tokenMeta.getAttribute('content') || '';
            form.appendChild(csrfInput);
        }

        Object.entries(data).forEach(([key, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = key;
            input.value = value;
            form.appendChild(input);
        });

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);

        setIsModalOpen(false);
        setFormStep(1);
        reset();
    };

    const renderDocumentPreview = () => {
        if (data.certificate_type === 'Tricycle') {
            return (
                <div className="space-y-4">
                    <p>This is to certify that</p>
                    <p className="font-bold underline text-lg">{data.resident_name.toUpperCase() || '___________________'}</p>
                    <p>Operator/Driver of</p>
                    <p className="font-bold">ONE(1) UNIT TRICYCLE (FRANCHISE # {data.franchise_no.toUpperCase() || '_______'})</p>
                    <p>With a Business/Trade name</p>
                    <p className="font-bold">{data.business_name.toUpperCase() || '___________________'}</p>
                    <p>is lawfully operating at</p>
                    <p className="font-bold">{data.business_address.toUpperCase() || '___________________'}</p>
                    
                    <div className="bg-blue-100 p-2 mt-6 border-l-4 border-blue-500 text-left text-xs font-sans">
                        <span className="font-bold text-blue-800 uppercase">Purpose: </span> 
                        {data.purpose.toUpperCase() || '___________________'}
                    </div>

                    <div className="mt-8 text-right">
                        <p className="font-bold text-sm">{data.signatory_name || '___________________'}</p>
                        <p className="text-xs italic">{data.signatory_title || 'Signatory Title'}</p>
                    </div>
                </div>
            );
        }

        return (
            <div className="space-y-4">
                <p>TO WHOM IT MAY CONCERN:</p>
                <p className="text-justify leading-relaxed mt-4">
                    This is to certify that <strong className="underline">{data.resident_name.toUpperCase() || '___________________'}</strong> is a bona fide resident of 
                    Purok {data.purok || '___________'}, Barangay San Jose, Puerto Princesa City.
                </p>
                <p className="text-justify leading-relaxed mt-4">
                    This certification is being issued upon the request of the above-named person for whatever legal purpose it may serve.
                </p>
                
                <div className="bg-blue-100 p-2 mt-6 border-l-4 border-blue-500 text-left text-xs font-sans">
                    <span className="font-bold text-blue-800 uppercase">Purpose: </span> 
                    {data.purpose.toUpperCase() || '___________________'}
                </div>

                <div className="mt-10 text-right">
                    <p className="font-bold text-sm">{data.signatory_name || '___________________'}</p>
                    <p className="text-xs italic">{data.signatory_title || 'Signatory Title'}</p>
                </div>
            </div>
        );
    };

    return (
        <ClerkLayout user={auth.user} active="certificates">
            <Head title="Dashboard" />

            <div className="space-y-6 max-w-7xl mx-auto">
                {/* HEADER ROW */}
                <div className="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
                    <div>
                        <h1 className="text-xl md:text-2xl font-bold text-gray-800">Certificate Issuance</h1>
                        <p className="text-sm text-gray-500">Manage and process resident certificate requests up to printing.</p>
                    </div>
                    <button 
                        onClick={() => { setIsModalOpen(true); setFormStep(1); reset(); }}
                        className="bg-[#3f6f3d] hover:bg-[#315b30] text-white px-4 py-2 rounded-md font-medium text-sm transition-colors shadow-sm"
                    >
                        + Issue New Certificate
                    </button>
                </div>

                {/* STATS CARDS */}
                <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div className="bg-white border-l-4 border-[#3f6f3d] rounded-r-lg shadow-sm p-4 sm:p-5">
                        <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Total Requests</h3>
                        <p className="text-2xl sm:text-3xl font-bold text-gray-800">4</p>
                    </div>
                    <div className="bg-white border-l-4 border-blue-500 rounded-r-lg shadow-sm p-4 sm:p-5">
                        <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Pending Review</h3>
                        <p className="text-2xl sm:text-3xl font-bold text-gray-800">1</p>
                    </div>
                    <div className="bg-white border-l-4 border-green-500 rounded-r-lg shadow-sm p-4 sm:p-5">
                        <h3 className="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-1">Issued Today</h3>
                        <p className="text-2xl sm:text-3xl font-bold text-gray-800">3</p>
                    </div>
                </div>

                {/* SEARCH AND FILTERS */}
                <div className="bg-white p-4 rounded-lg shadow-sm border border-gray-200 flex flex-col xl:flex-row justify-between gap-4">
                    <div className="flex flex-col sm:flex-row gap-3 flex-1">
                        <div className="relative flex-1 sm:max-w-xs">
                            <input 
                                type="text" 
                                placeholder="Search Control No. or Name" 
                                className="w-full px-3 py-2 text-sm border border-gray-300 rounded-md focus:outline-none focus:ring-1 focus:ring-[#3f6f3d] focus:border-[#3f6f3d]"
                            />
                        </div>
                        <select className="border border-gray-300 text-gray-600 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-1 focus:ring-[#3f6f3d] bg-white">
                            <option value="">All Types</option>
                            <option value="Residency">Residency</option>
                            <option value="Indigency">Indigency</option>
                            <option value="Tricycle">Tricycle Permit</option>
                        </select>
                    </div>
                </div>

                {/* DATA TABLE */}
                <div className="bg-white border border-gray-200 rounded-lg shadow-sm overflow-hidden">
                    <div className="overflow-x-auto">
                        <table className="w-full min-w-[800px] text-left border-collapse">
                            <thead>
                                <tr className="bg-[#f8f7f2] border-b border-gray-200 text-xs text-gray-500 uppercase tracking-wider">
                                    <th className="px-4 sm:px-6 py-4 font-medium">Control No.</th>
                                    <th className="px-4 sm:px-6 py-4 font-medium">Name</th>
                                    <th className="px-4 sm:px-6 py-4 font-medium">Type</th>
                                    <th className="px-4 sm:px-6 py-4 font-medium">Fee</th>
                                    <th className="px-4 sm:px-6 py-4 font-medium">Status</th>
                                    <th className="px-4 sm:px-6 py-4 text-center">Action</th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-gray-100 text-sm">
                                <tr className="hover:bg-gray-50 transition-colors">
                                    <td className="px-4 sm:px-6 py-4 font-medium text-gray-900 whitespace-nowrap">2026-04-770</td>
                                    <td className="px-4 sm:px-6 py-4 flex items-center gap-2 whitespace-nowrap">
                                        Maria Santos
                                        <span className="bg-green-100 text-green-700 text-[10px] px-1.5 py-0.5 rounded uppercase font-bold">RU</span>
                                    </td>
                                    <td className="px-4 sm:px-6 py-4 text-gray-600 whitespace-nowrap">Residency</td>
                                    <td className="px-4 sm:px-6 py-4 text-gray-600 whitespace-nowrap">₱100</td>
                                    <td className="px-4 sm:px-6 py-4 whitespace-nowrap">
                                        <span className="px-3 py-1 bg-[#e8f1e4] text-[#3f6f3d] rounded-full text-xs font-semibold border border-[#b8d6bf]">Issued</span>
                                    </td>
                                    <td className="px-4 sm:px-6 py-4 text-center whitespace-nowrap">
                                        <button className="bg-gray-600 hover:bg-gray-700 text-white px-3 py-1.5 rounded text-xs font-medium">Print</button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                {/* MODAL (Issue New Certificate) */}
                {isModalOpen && (
                    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                        <div className="bg-white rounded-lg shadow-xl w-full max-w-2xl overflow-hidden max-h-[90vh] flex flex-col">
                            <div className="bg-[#3f6f3d] text-white px-6 py-4 flex justify-between items-center shrink-0">
                                <h3 className="font-bold text-lg">
                                    {formStep === 1 ? 'Issue New Certificate' : 'Document Preview (Plain Text)'}
                                </h3>
                                <button onClick={() => setIsModalOpen(false)} className="text-white/80 hover:text-white text-xl font-bold">&times;</button>
                            </div>

                            <div className="overflow-y-auto flex-1">
                                {formStep === 1 ? (
                                    /* STEP 1: FORM ENTRY */
                                    <>
                                        <div className="flex border-b bg-gray-50">
                                            <button type="button" onClick={() => setModalMode('manual')} className={`flex-1 py-3 text-sm font-medium border-b-2 ${modalMode === 'manual' ? 'border-[#3f6f3d] text-[#3f6f3d] bg-white' : 'border-transparent text-gray-500'}`}>Form Entry</button>
                                            <button type="button" onClick={() => setModalMode('qr')} className={`flex-1 py-3 text-sm font-medium border-b-2 ${modalMode === 'qr' ? 'border-[#3f6f3d] text-[#3f6f3d] bg-white' : 'border-transparent text-gray-500'}`}>Scan QR 📷</button>
                                        </div>

                                        <form onSubmit={handleProceedToReview} className="p-6 space-y-4">
                                            {modalMode === 'qr' ? (
                                                <div className="text-center py-6 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
                                                    <button type="button" onClick={() => handleQRCodeScanned('RES-SAMPLE')} className="bg-[#3f6f3d] text-white text-xs px-4 py-2 rounded">Simulate QR Scan</button>
                                                </div>
                                            ) : (
                                                <div className="space-y-4">
                                                    <div className="grid grid-cols-2 gap-4">
                                                        <div>
                                                            <label className="block text-xs font-semibold uppercase text-gray-600 mb-1">Control No.</label>
                                                            <input type="text" value={data.control_no} readOnly className="w-full px-3 py-2 border bg-gray-100 rounded-md text-sm" />
                                                        </div>
                                                        <div>
                                                            <label className="block text-xs font-semibold uppercase text-gray-600 mb-1">Resident Name</label>
                                                            <input type="text" value={data.resident_name} onChange={e => setData('resident_name', e.target.value)} required className="w-full px-3 py-2 border rounded-md text-sm" />
                                                        </div>
                                                    </div>

                                                    <div>
                                                        <label className="block text-xs font-semibold uppercase text-gray-600 mb-1">Certificate Type</label>
                                                        <select value={data.certificate_type} onChange={e => handleCertificateTypeChange(e.target.value)} className="w-full px-3 py-2 border rounded-md text-sm bg-white">
                                                            <option value="Residency">Residency</option>
                                                            <option value="Indigency">Indigency</option>
                                                            <option value="Tricycle">Tricycle Permit</option>
                                                            <option value="Sari-sari Store">Sari-sari Store</option>
                                                        </select>
                                                    </div>

                                                    {(data.certificate_type === 'Tricycle' || data.certificate_type === 'Sari-sari Store') && (
                                                        <div className="p-4 bg-orange-50 border border-orange-100 rounded-lg space-y-3">
                                                            <h4 className="text-xs font-bold text-orange-800 uppercase">Business & Franchise Details</h4>
                                                            {data.certificate_type === 'Tricycle' && (
                                                                <div>
                                                                    <label className="block text-xs font-semibold text-gray-600 mb-1">Franchise No.</label>
                                                                    <input type="text" value={data.franchise_no} onChange={e => setData('franchise_no', e.target.value)} required className="w-full px-3 py-2 border rounded-md text-sm" />
                                                                </div>
                                                            )}
                                                            <div>
                                                                <label className="block text-xs font-semibold text-gray-600 mb-1">Business Name</label>
                                                                <input type="text" value={data.business_name} onChange={e => setData('business_name', e.target.value)} required className="w-full px-3 py-2 border rounded-md text-sm" />
                                                            </div>
                                                            <div>
                                                                <label className="block text-xs font-semibold text-gray-600 mb-1">Business Address</label>
                                                                <input type="text" value={data.business_address} onChange={e => setData('business_address', e.target.value)} required className="w-full px-3 py-2 border rounded-md text-sm" />
                                                            </div>
                                                        </div>
                                                    )}

                                                    <div className="grid grid-cols-2 gap-4">
                                                        <div>
                                                            <label className="block text-xs font-semibold uppercase text-gray-600 mb-1">Fee (₱)</label>
                                                            <input type="text" value={data.fee} readOnly className="w-full px-3 py-2 border bg-gray-100 rounded-md text-sm" />
                                                        </div>
                                                        <div>
                                                            <label className="block text-xs font-semibold uppercase text-gray-600 mb-1">Purpose</label>
                                                            <input type="text" value={data.purpose} onChange={e => setData('purpose', e.target.value)} required className="w-full px-3 py-2 border rounded-md text-sm" />
                                                        </div>
                                                    </div>

                                                    <div className="bg-blue-50 p-4 border border-blue-100 rounded-lg">
                                                        <label className="block text-xs font-semibold uppercase text-blue-800 mb-1">Signatory</label>
                                                        <select required value={data.signatory_name} onChange={e => handleSignatoryChange(e.target.value)} className="w-full px-3 py-2 border rounded-md text-sm bg-white">
                                                            <option value="">Select Signatory...</option>
                                                            {SIGNATORIES.map((sig, idx) => (
                                                                <option key={idx} value={sig.name}>{sig.name} - {sig.title}</option>
                                                            ))}
                                                        </select>
                                                    </div>
                                                </div>
                                            )}

                                            <div className="flex justify-end pt-4 border-t mt-4">
                                                <button type="submit" className="px-4 py-2 bg-[#3f6f3d] text-white rounded-md text-sm font-medium shadow-sm hover:bg-[#315b30]">
                                                    Proceed to Text Preview &rarr;
                                                </button>
                                            </div>
                                        </form>
                                    </>
                                ) : (
                                    /* STEP 2: PLAIN TEXT DOCUMENT PREVIEW */
                                    <div className="p-6 bg-gray-100">
                                        <div className="bg-white border border-gray-300 shadow-lg mx-auto p-8 rounded-sm font-serif text-gray-800 text-center max-w-[500px]">
                                            <h2 className="text-xl font-bold tracking-[0.2em] mb-8">C E R T I F I C A T I O N</h2>
                                            
                                            {renderDocumentPreview()}
                                            
                                        </div>

                                        <div className="flex justify-between items-center mt-6">
                                            <button type="button" onClick={() => setFormStep(1)} className="px-4 py-2 border border-gray-300 text-gray-700 bg-white rounded-md text-sm hover:bg-gray-50 shadow-sm">
                                                &larr; Back to Edit
                                            </button>
                                            <button type="button" onClick={handleFinalSubmit} disabled={processing} className={`px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white shadow-md rounded-md text-sm font-bold ${processing ? 'opacity-50' : ''}`}>
                                                Confirm & Download Original .docx
                                            </button>
                                        </div>
                                    </div>
                                )}
                            </div>
                        </div>
                    </div>
                )}
            </div>
        </ClerkLayout>
    );
}